<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Exceptions\InvalidListingQuery;
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
    public function show(Request $request, string $path, CatalogReader $reader, SeoMeta $seo, PaginationLinks $pagination): View|RedirectResponse|Response
    {
        $category = $reader->category($path);

        if ($category === null) {
            return NotFoundResponse::make();
        }

        try {
            $listing = $reader->listing(ListingRequest::query($request, $category->id));
        } catch (InvalidListingQuery) {
            return NotFoundResponse::make();
        }

        if ($listing->page > $listing->lastPage) {
            return NotFoundResponse::make();
        }

        if (trim($path, '/') !== $category->path) {
            return redirect()->to($category->url.$pagination->suffix($listing), 301);
        }

        return view('storefront.category', [
            'category' => $category,
            'listing' => $listing,
            'pagination' => $pagination->build($category->url, $listing),
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forCategory($category, $listing),
        ]);
    }
}
