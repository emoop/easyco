<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Exceptions\InvalidListingQuery;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\Filters\FilterSelection;
use App\Storefront\Filters\FilterView;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\ListingRequest;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\PaginationLinks;
use App\Storefront\Support\SeoMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /product-category/{path}
 *
 * VIEW `storefront.category` receives EXACTLY:
 *   category    App\Storefront\ReadModels\CategoryPage   (canonical path/url, breadcrumbs, shown children)
 *   listing     App\Storefront\ReadModels\ListingPage    (the category's products, descendants included)
 *   pagination  array{current: int, last: int, prev: ?string, next: ?string, pages: list<array{number: int, url: string, current: bool}>}
 *   categories  list<App\Storefront\ReadModels\CategoryNode>  the menu
 *   seo         array{title: string, description: string, canonical: string}
 *
 * The category is resolved by the LAST segment of the path; a path that is not the canonical one (a wrong parent
 * prefix, a different case) is a 301 to the read model's url, keeping only the whitelisted query keys. The query string
 * is read ONLY through ListingRequest (`page`, `per_page`, `sort`; everything else is ignored). An invalid query value,
 * a price sort (not offered until S7) and a PAGE BEYOND THE LAST page are all the storefront's one 404; page 1 of an
 * empty listing is a 200.
 */
final class CategoryController extends Controller
{
    public function show(Request $request, string $path, CatalogReader $reader, SeoMeta $seo, PaginationLinks $pagination, StorefrontAppearance $appearance, FilterCatalog $filters): View|RedirectResponse|Response
    {
        // CD-1a: the PUBLISHED «Каталог» size is the default of `per_page` (one cached read, no query)
        $perPage = $appearance->catalogPerPage();

        $category = $reader->category($path);

        if ($category === null) {
            return NotFoundResponse::make();
        }

        // CT-3: a request WITH a `filter` parameter is resolved against the shop's filters first; one without it asks for them only when the page query says the shop has any (no query of their own otherwise)
        $filtered = $request->query->has('filter');
        $catalog = $filtered ? $filters->all() : [];

        try {
            $query = ListingRequest::query($request, $category->id, $perPage);
            $selection = FilterSelection::fromRequest($request, $catalog);
        } catch (InvalidListingQuery) {
            return NotFoundResponse::make();
        }

        // one selection, one URL: the same choice in another order is a 301 to the canonical one, before any listing query
        if (! $selection->canonical) {
            return redirect()->to($category->url.$pagination->suffixFor($query->page, $query->perPage->value, $query->sort->value, $selection->query(), $perPage), 301);
        }

        $listing = $reader->listing($query, $selection->map);
        ! $filtered && $listing->hasFilterable && $catalog = $filters->all();

        if ($listing->page > $listing->lastPage) {
            return NotFoundResponse::make();
        }

        if (trim($path, '/') !== $category->path) {
            return redirect()->to($category->url.$pagination->suffix($listing, null, $perPage, $selection->query()), 301);
        }

        // CT-3: the values that really give products, one query per filter of the shop; nothing at all when the shop has no filters
        $facets = [];

        foreach ($catalog as $filter) {
            $facets[$filter['id']] = $reader->filterValueIds($query, $selection->map, $filter['id']);
        }

        $view = view('storefront.category', [
            'category' => $category,
            'listing' => $listing,
            'pagination' => $pagination->build($category->url, $listing, $perPage, $selection->query()),
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forCategory($category, $listing, ! $selection->isEmpty()),
            'filters' => $catalog === [] ? null : FilterView::build($catalog, $selection, $facets, $category->url, $listing->perPage, $query->sort->value, $perPage, $pagination),
        ]);

        return $selection->isEmpty() ? $view : response($view)->header('X-Robots-Tag', 'noindex, follow');
    }
}
