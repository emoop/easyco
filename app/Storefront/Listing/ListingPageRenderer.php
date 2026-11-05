<?php

namespace App\Storefront\Listing;

use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Exceptions\InvalidListingQuery;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\Filters\FilterSelection;
use App\Storefront\Filters\FilterView;
use App\Storefront\ReadModels\CategoryPage;
use App\Storefront\ReadModels\ListingScope;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Reader\ImageSetBuilder;
use App\Storefront\Support\ListingRequest;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\PaginationLinks;
use App\Storefront\Support\SeoMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * P2: ONE listing page for a category, a brand, a season and a tag (the body that used to live in CategoryController::show). The SUBJECT is what differs: its read model (`CategoryPage`: name, canonical url,
 * breadcrumbs, children, banner), its scope (what is FIXED in the product query) and, for a brand / season / tag page, its own filter, which is not a filter on its own page: it is not offered (side panel, top
 * bar, chips: no facet query either) and `filter[brand]` (…) in the URL of a brand page is ignored. Everything else (listing, other filters, sort, pagination, canonical redirects, SEO, the view and its CSS) is
 * the category page's, byte for byte.
 *
 * VIEW `storefront.category` receives what CategoryController documents.
 */
final class ListingPageRenderer
{
    public function __construct(
        private readonly CatalogReader $reader,
        private readonly SeoMeta $seo,
        private readonly PaginationLinks $pagination,
        private readonly StorefrontAppearance $appearance,
        private readonly FilterCatalog $filters,
    ) {
    }

    /**
     * @param  bool  $pathMismatch  the requested path / slug is not the canonical one (a wrong parent prefix, another case): a 301 to the page's url once the listing is known to exist
     */
    public function render(Request $request, CategoryPage $category, ListingScope $scope, bool $pathMismatch): View|RedirectResponse|Response
    {
        $reader = $this->reader;
        $pagination = $this->pagination;

        // CD-1a: the PUBLISHED «Каталог» size is the default of `per_page` (one cached read, no query)
        $perPage = $this->appearance->catalogPerPage();

        // CD-1b: the filters form sends an EMPTY `sort` for the default option; it is not a sort, it is a reason to answer with the URL that has none
        // DRIBNI-1: read the raw value, not `query->get()`: Symfony throws a 400 for an ARRAY value (`sort[]=x`); an array is not an empty sort, it falls through to the 404 of an unknown sort
        $emptySort = $request->query->has('sort') && in_array($request->query->all()['sort'] ?? null, ['', null], true);
        $emptySort && $request->query->remove('sort');

        // P2: the page's own kind of filter (a brand page: `brand`) is fixed by the page, so it is never offered and its choice in the URL is ignored
        $own = $scope === ListingScope::CATEGORY ? null : $scope->value;
        $own === null || $this->ignoreOwnFilter($request, $own);

        // CT-3: a request WITH a `filter` parameter is resolved against the shop's filters first; one without it asks for them only when the page query says the shop has any (no query of their own otherwise)
        // P2: `featured` is resolved against the shop's list too (a hidden filter's parameter is the 404)
        $filtered = $request->query->has('filter') || $request->query->has('featured');
        $catalog = $filtered ? $this->catalog($own) : [];

        try {
            $query = ListingRequest::query($request, $category->id, $perPage, $scope);
            $selection = FilterSelection::fromRequest($request, $catalog);
        } catch (InvalidListingQuery) {
            return NotFoundResponse::make();
        }

        // one selection, one URL: the same choice in another order, the form's array form (`filter[12][]=7`) and an empty sort are a 301 to the canonical URL (the plain page URL for an empty
        // submission), before any listing query
        if (! $selection->canonical || $emptySort) {
            return redirect()->to($category->url.$pagination->suffixFor($query->page, $query->perPage->value, $query->sort->value, $selection->query(), $perPage), 301);
        }

        // SIZES-1: the card images' `sizes` follow the PUBLISHED column counts of this grid (the home page keeps its own default)
        $columns = $this->appearance->catalogColumns();
        $listing = $reader->listing($query, $selection->constraintMap(), ImageSetBuilder::cardSizes($columns['desktop'], $columns['phone'], $this->appearance->catalogGapX()));
        // P2: a shown «Препоръчани» is a filter even in a shop with no attribute, brand, season or tag (read from the appearance: no query)
        ! $filtered && ($listing->hasFilterable || $this->filters->hasShownSwitch()) && $catalog = $this->catalog($own);

        if ($listing->page > $listing->lastPage) {
            return NotFoundResponse::make();
        }

        if ($pathMismatch) {
            return redirect()->to($category->url.$pagination->suffix($listing, null, $perPage, $selection->query()), 301);
        }

        // CT-3: the values that really give products, one query per filter of the shop; nothing at all when the shop has no filters
        $facets = [];

        foreach ($catalog as $filter) {
            // a switch has no values: no facet query
            $filter['display'] === 'switch' || $facets[$filter['id']] = $reader->filterValueIds($query, $selection->constraintMap(), $filter['id']);
        }

        $filterView = $catalog === [] ? null : FilterView::build($catalog, $selection, $facets, $category->url, $listing->perPage, $query->sort->value, $perPage, $pagination);
        $sorts = FilterView::sorts($category->url, $listing->perPage, $query->sort->value, $selection->query(), $perPage, $pagination);
        $layout = $this->appearance->catalogLayout();

        $view = view('storefront.category', [
            'category' => $category,
            'listing' => $listing,
            'pagination' => $pagination->build($category->url, $listing, $perPage, $selection->query()),
            'categories' => $reader->categoryTree(),
            'seo' => $this->seo->forCategory($category, $listing, ! $selection->isEmpty()),
            'filters' => $filterView,
            // CD-1b: the sorts on offer, the layout / side of the PUBLISHED copy, the form's own bits and the optional banner (page 1, no filter chosen)
            'sorts' => $sorts,
            'layout' => $layout['layout'],
            'side' => $layout['side'],
            'sideWidth' => $layout['sideWidth'],
            'perPageHidden' => $listing->perPage !== $perPage ? $listing->perPage : null,
            'clearUrl' => $category->url.$pagination->suffixFor(1, $listing->perPage, 'newest', '', $perPage),
            'headerBlock' => $this->headerBlock($category, $query->page, $selection),
            // P3: the page's template (title cloud, top block, bottom block / carousel): null = the page it always was
            'content' => $this->content($category, $scope, $query->page, $reader),
        ]);

        return $selection->isEmpty() ? $view : response($view)->header('X-Robots-Tag', 'noindex, follow');
    }

    /**
     * P3: what the page's TEMPLATE adds, or null (no template, or one that prints nothing: the page is byte-identical to today's). The cloud is on every page of the pagination; the top and the bottom block only
     * on page 1 (no duplicate content on `?page=2`), whatever the sort or filters. A category's OWN banner («Блок отгоре» of the category, `headerBlock`) is the more specific top block: where it is set it keeps
     * the place and the template's top block is not printed. The carousel's `current` source is read for THIS page's subject, not filtered by the visitor's choices.
     *
     * @return array{cloud: list<array{name: string, url: string}>|null, label: string, top: array<string, mixed>|null, bottom: array<string, mixed>|null}|null
     */
    private function content(CategoryPage $category, ListingScope $scope, int $page, CatalogReader $reader): ?array
    {
        $found = $this->appearance->listingContent($scope, (int) $category->id);

        if ($found === null) {
            return null;
        }

        $template = $found['template'];
        $slot = static function (?array $section) use ($found): ?array {
            $prepared = $section === null ? null : \App\Storefront\Home\HomeView::prepare(['layout' => ['spacing' => '0', 'sections' => [$section]], 'blocks' => $found['blocks']], true);

            return $prepared === null || $prepared['items'] === [] ? null : $prepared;
        };

        $top = $page === 1 && $category->headerBlockId === null ? $slot(\App\Storefront\Content\ContentTemplate::topSection($template)) : null;
        $bottom = $page === 1 ? $slot(\App\Storefront\Content\ContentTemplate::bottomSection($template)) : null;

        if ($bottom !== null) {
            $id = (int) $category->id;
            $bottom = \App\Storefront\Home\HomeView::fill($bottom, static fn (string $source, ?int $entity, string $sort, int $count, string $sizes): array => $reader->homeProducts($source, $entity, $sort, $count, $sizes, $scope, $id), $this->appearance->catalogGapX(), $this->appearance->catalogColumns()['phone']);
            $bottom = $bottom['items'] === [] ? null : $bottom;
        }

        $cloud = $template['cloud'] ? $reader->cloud($scope, (int) $category->id) : null;

        return $cloud === null && $top === null && $bottom === null ? null : ['cloud' => $cloud, 'label' => $scope === ListingScope::CATEGORY ? (string) __('storefront.subcategories') : (string) __('storefront.cloud_categories'), 'top' => $top, 'bottom' => $bottom];
    }

    /**
     * The shop's filters without the page's own kind. @return list<array<string, mixed>>
     */
    private function catalog(?string $own): array
    {
        $all = $this->filters->all();

        return $own === null ? $all : array_values(array_filter($all, static fn (array $filter): bool => $filter['id'] !== $own));
    }

    /** `filter[brand]=…` on a brand page: dropped from the request before anything reads it (a `filter` left empty goes with it). A `filter` that is not an array is left alone: it is the 404 it always was. */
    private function ignoreOwnFilter(Request $request, string $own): void
    {
        $all = $request->query->all();

        if (! isset($all['filter']) || ! is_array($all['filter']) || ! array_key_exists($own, $all['filter'])) {
            return;
        }

        unset($all['filter'][$own]);
        $all['filter'] === [] && $all = array_diff_key($all, ['filter' => 1]);
        $request->query->replace($all);
    }

    /** CD-1b: the sanitised HTML of the category's «Блок отгоре» — only on page 1 and only when no filter is chosen; a block that is gone or empty prints nothing. */
    private function headerBlock(CategoryPage $category, int $page, FilterSelection $selection): ?string
    {
        if ($category->headerBlockId === null || $page !== 1 || ! $selection->isEmpty()) {
            return null;
        }

        $html = \App\Content\HtmlBlocks\HtmlBlockModel::renderMany([$category->headerBlockId])[$category->headerBlockId] ?? '';

        return trim($html) === '' ? null : $html;
    }
}
