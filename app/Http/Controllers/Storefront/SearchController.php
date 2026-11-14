<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Reader\ImageSetBuilder;
use App\Storefront\Search\ProductSearch;
use App\Storefront\Search\SearchFeature;
use App\Storefront\Search\SearchQuery;
use App\Storefront\Search\SearchSort;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\PaginationLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /search?q=… (SRCH-1, search.md): the results page, laid out like a category listing (its cards, toolbar, sort and pagination partials, the shop's published «Каталог» layout).
 *
 * The storefront depends on `ProductSearch` only (the container decides the engine). The query string is read through whitelists: `q` (any value is accepted and normalised, never an error),
 * `sort` (a closed list) and `page` (digits); an unknown sort or a bad page is the storefront's one 404, a page past the last one too. An empty or too short query is a 200 with a hint and the field again.
 * Nothing is stored (no search terms are logged), every page is `noindex, follow` with no canonical, and the HTML depends on the URL alone (no session, no cookie).
 *
 * While search is switched off (SearchFeature) the route answers the storefront's 404, as it did before the feature existed.
 */
final class SearchController extends Controller
{
    public function show(Request $request, ProductSearch $search, CatalogReader $reader, StorefrontAppearance $appearance, PaginationLinks $pagination): View|Response
    {
        if (! SearchFeature::enabled()) {
            return NotFoundResponse::make();
        }

        $input = $request->query->all();
        $sort = SearchSort::fromRequest($input['sort'] ?? null);
        $rawPage = $input['page'] ?? 1;

        if ($sort === null || (! is_int($rawPage) && ! (is_string($rawPage) && preg_match('/^[0-9]{1,3}$/D', $rawPage) === 1)) || (int) $rawPage < 1 || (int) $rawPage > 200) {
            return NotFoundResponse::make();
        }

        $query = SearchQuery::fromInput($input['q'] ?? null);
        $perPage = $appearance->catalogPerPage();
        $page = (int) $rawPage;
        $state = $query->isEmpty() ? 'empty' : ($query->searchable() ? 'results' : 'short');
        $cards = [];
        $total = 0;
        $lastPage = 1;

        if ($state === 'results') {
            $result = $search->search($query, $sort, $page, $perPage);

            if ($result->page > $result->lastPage || ($result->total === 0 && $page > 1)) {
                return NotFoundResponse::make();
            }

            $columns = $appearance->catalogColumns();
            $cards = $reader->cardsForIds($result->ids, ImageSetBuilder::cardSizes($columns['desktop'], $columns['phone'], $appearance->catalogGapX()));
            $total = $result->total;
            $lastPage = $result->lastPage;
            $state = $total === 0 ? 'none' : 'results';
        } elseif ($page > 1) {
            return NotFoundResponse::make();
        }

        // The links keep the query: `?q=…` first, then a non-default sort, then the page (PaginationLinks adds `page` and leaves the default per-page out).
        $keep = static fn (SearchSort $each): string => http_build_query(array_filter(['q' => $query->text, 'sort' => $each === SearchSort::RELEVANCE ? null : $each->value], static fn ($value): bool => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);
        $base = SearchFeature::path();
        // PaginationLinks leaves out a sort equal to 'newest' and this page's own sort is already in `$keep`, so the listing it is given says 'newest'
        $listing = new ListingPage(['sort' => 'newest'], $cards, $page, $perPage, $total, $lastPage, []);
        $sorts = $state === 'results' ? array_map(static fn (SearchSort $each): array => [
            'value' => $each === SearchSort::RELEVANCE ? '' : $each->value,
            'label' => (string) __($each === SearchSort::RELEVANCE ? 'storefront.search_sort_relevance' : 'storefront.sort.'.$each->value),
            'url' => $base.($keep($each) === '' ? '' : '?'.$keep($each)),
            'current' => $each === $sort,
        ], SearchSort::cases()) : [];

        $title = $query->isEmpty() ? (string) __('storefront.search_title_empty') : (string) __('storefront.search_title', ['query' => $query->text]);

        $view = view('storefront.search', [
            'query' => $query,
            'state' => $state,
            'title' => $title,
            'listing' => $listing,
            'pagination' => $pagination->build($base, $listing, $perPage, $keep($sort)),
            'categories' => $reader->categoryTree(),
            'seo' => ['title' => $title.' — '.config('app.name'), 'description' => '', 'canonical' => null, 'noindex' => true, 'robots' => 'noindex, follow'],
            'sorts' => $sorts,
            // no filters here, so no side panel: the grid always takes the full width (the shop's «Каталог» columns, gap and page size still apply)
            'layout' => 'full',
            'side' => 'left',
            'sideWidth' => 'wide',
        ]);

        return response($view)->header('X-Robots-Tag', 'noindex, follow');
    }
}
