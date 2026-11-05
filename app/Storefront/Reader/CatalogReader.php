<?php

namespace App\Storefront\Reader;

use App\Services\CatalogScopeResolver;
use App\Storefront\Exceptions\ListingOptionNotAvailable;
use App\Storefront\Filters\FilterConstraint;
use App\Storefront\ReadModels\Breadcrumb;
use App\Storefront\ReadModels\CategoryNode;
use App\Storefront\ReadModels\CategoryPage;
use App\Storefront\ReadModels\GalleryItem;
use App\Storefront\ReadModels\ImageSet;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\ListingScope;
use App\Storefront\ReadModels\ListingSort;
use App\Storefront\ReadModels\PriceBlock;
use App\Storefront\ReadModels\ProductCard;
use App\Storefront\ProductSummary\PurchaseView;
use App\Storefront\ReadModels\ProductPage;
use App\Storefront\ReadModels\VariationView;
use App\Storefront\Url\StorefrontUrls;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Inventory\Persistence\Eloquent\StockLevelModel;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\PriceRange;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The storefront's read layer (storefront-design.md §2.3): the product page, a category page, a listing page and the
 * category tree, as immutable read models. NO routes, views, controllers or caching live here (S2/S4); the sandbox is
 * untouched.
 *
 * ONE RULE for what is visible: StorefrontVisibility. Every read below goes through it.
 *
 * QUERY BUDGET (§2.5), pinned by tests with 5 and with 24 products, and EQUAL for both: one query per concern per
 * page, never per row.
 *   listing:        category structure (2, only for a category scope) + page rows (1) + brand eager load (1)
 *                   + count (1) + the page's assets (1) + shown variations (1) + stock (1) + prices (CatalogScopeResolver + PriceRangeResolver, for the SHOWN variations only)
 *   product page:   product (1) + brand (1) + shown variations (1) + their attribute values (1) + stock (1) + prices
 *                   (CatalogScopeResolver + PriceRangeResolver) + gallery with variants (1) + the product's categories
 *                   (1) + category structure (2)
 *   category tree:  2 (CategoryIndex)
 *
 * A slug or path that is not acceptable (StorefrontUrls) returns null WITHOUT a query. Lookups are parameter-bound.
 * CASE: the product lookup follows the database collation (case- and accent-insensitive on MySQL utf8mb4_unicode_ci,
 * case-sensitive on SQLite); the returned model always carries the stored slug and its canonical url, so a caller can
 * redirect a differently-cased request. The category lookup lower-cases both sides (slugs are stored lower-case).
 *
 * Domain facts read through domain-shaped code: `purchasable` mirrors Variation::isEffectivelyPurchasable() (pinned
 * against the domain method by a test); prices come from the existing batched Pricing resolvers.
 *
 * Scoped: the memoised CategoryIndex lives for one request (a worker resets scoped instances between requests).
 */
#[Scoped]
final class CatalogReader
{
    public function __construct(
        private readonly StorefrontVisibility $visibility,
        private readonly CategoryIndex $categories,
        private readonly StorefrontUrls $urls,
        private readonly ImageSetBuilder $images,
        private readonly CatalogScopeResolver $scopeResolver,
        private readonly PriceRangeResolver $priceResolver,
    ) {
    }

    // ================================================================== product page

    public function product(string $slug): ?ProductPage
    {
        if (! $this->urls->isValidSlug($slug)) {
            return null;
        }

        $product = $this->visibility->products(ProductModel::query())
            ->where('catalog_products.slug', $slug)
            ->select(['id', 'type', 'name', 'slug', 'short_description', 'description', 'brand_id', 'base_sku'])
            ->with('brand:id,name,slug')
            ->first();

        if ($product === null) {
            return null;
        }

        $productId = (int) $product->id;

        $variations = $this->visibility->variations(VariationModel::query())
            ->where('catalog_variations.product_id', $productId)
            ->orderBy('catalog_variations.sort_order')
            ->orderBy('catalog_variations.id')
            ->get(['catalog_variations.id', 'catalog_variations.sku', 'catalog_variations.status', 'catalog_variations.is_purchasable']);

        $variationIds = $variations->map(fn (VariationModel $v): string => (string) $v->id)->all();

        $attributeRows = $variationIds === [] || $product->type !== ProductType::VARIABLE->value
            ? []
            : DB::table('catalog_variation_attribute_values as vav')
                ->join('catalog_attribute_definitions as def', 'def.id', '=', 'vav.attribute_definition_id')
                ->join('catalog_attribute_values as val', 'val.id', '=', 'vav.attribute_value_id')
                ->whereIn('vav.variation_id', $variationIds)
                ->orderBy('def.id')
                ->orderBy('val.sort_order')
                ->orderBy('val.id')
                ->get(['vav.variation_id', 'def.id as definition_id', 'def.name as definition_name', 'def.display_type as display_type', 'val.value as value', 'val.colour as colour'])
                ->all();

        $stock = $this->stockByVariation($variationIds);
        $quotes = $this->quotesByVariation($variationIds);

        $attributesOf = [];
        $optionValues = [];
        $optionNames = [];
        $optionDisplayType = [];
        $optionColours = [];

        foreach ($attributeRows as $row) {
            $attributesOf[(string) $row->variation_id][] = ['name' => (string) $row->definition_name, 'value' => (string) $row->value];
            $optionNames[(int) $row->definition_id] = (string) $row->definition_name;
            $optionValues[(int) $row->definition_id][(string) $row->value] = true;
            $optionDisplayType[(int) $row->definition_id] = (string) $row->display_type;
            $optionColours[(int) $row->definition_id][(string) $row->value] = $row->colour === null ? null : (string) $row->colour;
        }

        $options = [];
        $optionDisplay = [];

        foreach ($optionNames as $definitionId => $name) {
            $options[] = ['name' => $name, 'values' => array_map('strval', array_keys($optionValues[$definitionId]))];
            // AT-1: the colour is exposed only while the attribute is shown as colour
            $type = $optionDisplayType[$definitionId];
            $optionDisplay[] = ['id' => $definitionId, 'name' => $name, 'displayType' => $type, 'colours' => $type === 'swatch' ? $optionColours[$definitionId] : []];
        }

        $views = [];

        foreach ($variations as $variation) {
            $id = (string) $variation->id;
            $attributes = $attributesOf[$id] ?? [];

            $views[] = new VariationView(
                id: $id,
                sku: $variation->sku === null ? null : (string) $variation->sku,
                label: implode(' / ', array_column($attributes, 'value')),
                attributes: $attributes,
                price: $this->productPrice([$id], $quotes),
                inStock: ($stock[$id] ?? 0) > 0,
                purchasable: $variation->status === StorefrontVisibility::VARIATION_STATUS->value && (bool) $variation->is_purchasable,
                // DRIBNI-3: the same cap as the cart's (PurchaseView::MAX_QUANTITY), not a second 99
                maxQuantity: min(PurchaseView::MAX_QUANTITY, $stock[$id] ?? 0),
            );
        }

        $gallery = $this->gallery($productId, (string) $product->name);

        return new ProductPage(
            id: (string) $productId,
            slug: (string) $product->slug,
            url: $this->urls->product((string) $product->slug),
            name: (string) $product->name,
            type: (string) $product->type,
            shortDescription: $product->short_description === null ? null : (string) $product->short_description,
            description: $product->description === null ? null : (string) $product->description,
            brand: $this->brandOf($product),
            breadcrumbs: $this->productBreadcrumbs($productId, (string) $product->name, (string) $product->slug),
            images: $gallery['images'],
            price: $this->productPrice($variationIds, $quotes),
            inStock: array_filter($stock, fn (int $quantity): bool => $quantity > 0) !== [],
            options: $options,
            variations: $views,
            badges: [],
            gallery: $gallery['items'],
            sku: $product->base_sku === null || trim((string) $product->base_sku) === '' ? null : (string) $product->base_sku,
            optionDisplay: $optionDisplay,
        );
    }

    // ================================================================== categories

    /** @return list<CategoryNode> */
    public function categoryTree(): array
    {
        return $this->categories->tree();
    }

    /** Resolves by the LAST segment (slugs are globally unique); the page carries the CANONICAL path. */
    public function category(string $path): ?CategoryPage
    {
        $segments = $this->urls->pathSegments($path);

        if ($segments === null) {
            return null;
        }

        $id = $this->categories->shownIdBySlug($segments[count($segments) - 1]);

        if ($id === null) {
            return null;
        }

        $row = $this->categories->row($id);
        $canonical = $this->categories->path($id);

        return new CategoryPage(
            id: (string) $id,
            name: $row['name'],
            slug: $row['slug'],
            path: $canonical,
            url: $this->urls->category($canonical),
            productCount: $this->categories->productCount($id),
            breadcrumbs: $this->categories->breadcrumbs($id),
            children: $this->categories->shownChildren($id),
            headerBlockId: $row['header_block_id'] ?? null,
        );
    }

    /**
     * P2: a brand, a season or a tag as the SUBJECT of a listing page, in the shape the listing view already prints for a category (CategoryPage: no children, no banner). The slug is looked up in its table
     * (unique); a slug outside the URL alphabet is refused before any query, an unknown one is null (the storefront's 404). One indexed read. The breadcrumb's parent step is plain text (no index page exists):
     * its url is ''.
     */
    public function subject(ListingScope $scope, string $slug): ?CategoryPage
    {
        $table = match ($scope) {
            ListingScope::BRAND => 'catalog_brands',
            ListingScope::SEASON => 'catalog_seasons',
            ListingScope::TAG => 'catalog_tags',
            default => null,
        };

        if ($table === null || ! $this->urls->isValidSlug($slug)) {
            return null;
        }

        $row = DB::table($table)->where('slug', $slug)->first(['id', 'name', 'slug']);

        if ($row === null) {
            return null;
        }

        $url = match ($scope) {
            ListingScope::BRAND => $this->urls->brand((string) $row->slug),
            ListingScope::SEASON => $this->urls->season((string) $row->slug),
            default => $this->urls->tag((string) $row->slug),
        };

        return new CategoryPage(
            id: (string) $row->id,
            name: (string) $row->name,
            slug: (string) $row->slug,
            path: (string) $row->slug,
            url: $url,
            productCount: 0,
            breadcrumbs: [new Breadcrumb((string) __('storefront.crumb_'.$scope->value), ''), new Breadcrumb((string) $row->name, $url)],
            children: [],
        );
    }

    /**
     * P3: the quick-navigation cloud of a listing page's title. A category: its shown sub-categories (the category index is already loaded for the menu: no query of its own). A brand, season or tag: the categories
     * that hold VISIBLE products of it (a hidden, draft or archived product brings no category in): ONE grouped read over the visible (category, product) pairs. At most `$max` links, by the number of visible
     * products (most first), then by name. Names are plain text (the view escapes them).
     *
     * @return list<array{name: string, url: string}>
     */
    public function cloud(ListingScope $scope, int $id, int $max = 12): array
    {
        $rows = [];

        if ($scope === ListingScope::CATEGORY) {
            foreach ($this->categories->shownChildren($id) as $node) {
                $rows[] = ['name' => $node->name, 'url' => $node->url, 'n' => $node->productCount, 'id' => (int) $node->id];
            }
        } else {
            $pairs = $this->visibility->visibleCategoryPairs()
                ->select('catalog_product_categories.category_id')
                ->selectRaw('count(distinct catalog_product_categories.product_id) as n')
                ->groupBy('catalog_product_categories.category_id');

            match ($scope) {
                ListingScope::BRAND => $pairs->where('catalog_products.brand_id', $id),
                ListingScope::SEASON => $pairs->where('catalog_products.season_id', $id),
                ListingScope::TAG => $pairs->whereIn('catalog_products.id', DB::table('catalog_product_tags')->where('tag_id', $id)->select('product_id')),
                default => null,
            };

            foreach ($pairs->get() as $pair) {
                $category = $this->categories->row((int) $pair->category_id);

                $category === null || $rows[] = ['name' => (string) $category['name'], 'url' => $this->urls->category($this->categories->path((int) $pair->category_id)), 'n' => (int) $pair->n, 'id' => (int) $pair->category_id];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$b['n'], mb_strtolower($a['name'], 'UTF-8'), $a['id']] <=> [$a['n'], mb_strtolower($b['name'], 'UTF-8'), $b['id']]);

        return array_map(static fn (array $row): array => ['name' => $row['name'], 'url' => $row['url']], array_slice($rows, 0, max(0, $max)));
    }

    // ================================================================== listing

    /**
     * @throws ListingOptionNotAvailable for a price sort or a price filter (they need S7's price_from_minor)
     */
    public function listing(ListingQuery $query, array $filters = [], ?string $cardSizes = null): ListingPage
    {
        if ($query->hasPriceFilter() || $query->sort === ListingSort::PRICE_ASC || $query->sort === ListingSort::PRICE_DESC) {
            throw new ListingOptionNotAvailable('Price filtering and price sorting arrive with the storefront search stage (S7).');
        }

        $products = $this->listingProducts($query, $filters);

        $paginator = $products->paginate($query->perPage->value, ['*'], 'page', $query->page);

        return new ListingPage(
            query: $query->toArray(),
            items: $this->cards($paginator->items(), $cardSizes ?? ImageSetBuilder::SIZES_CARD),
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: $paginator->lastPage(),
            facets: [],
            hasFilterable: self::hasFilterable($paginator->items()[0] ?? null),
        );
    }

    /**
     * The query of a listing, shared by listing() and homeProducts(): the visible products with the card's columns (the two photos, the filter flags), the scope, the visitor's filters and the sort. No page, no
     * limit and no count: the caller adds what it needs.
     *
     * @param  array<int|string, list<int>>  $filters
     */
    private function listingProducts(ListingQuery $query, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $products = $this->visibility->products(ProductModel::query())
            ->select(['catalog_products.id', 'catalog_products.slug', 'catalog_products.name', 'catalog_products.brand_id'])
            ->addSelect(['first_media_id' => self::firstImageIdSubquery(), 'second_media_id' => self::secondImageIdSubquery()])
            // CT-3: «is there a filterable attribute?» rides on the page query (one uncorrelated scalar subquery), so a shop with no filters pays no query of its own for them
            ->addSelect(['has_filters' => DB::table('catalog_attribute_definitions')->selectRaw('1')->where('is_filterable', true)->limit(1)])
            ->with('brand:id,name,slug');

        // CT-4: the same scalar-subquery trick for the built-in filters (brand / season / tag): a shop with only brands still shows its filters, and none of it costs a query of its own. Which of them are SWITCHED ON is
        // decided when the filter list is composed (FilterCatalog::all), not here: this query reads no appearance setting
        foreach (['brand' => 'catalog_brands', 'season' => 'catalog_seasons', 'tag' => 'catalog_tags'] as $kind => $table) {
            $products->addSelect(['has_'.$kind.'_filter' => DB::table($table)->selectRaw('1')->limit(1)]);
        }

        $this->applyScope($products, $query);
        // CT-3: the visitor's filters (attribute id => value ids, already resolved against the shop's own filters); none = today's query
        $filters === [] || FilterConstraint::apply($products, $filters);

        match ($query->sort) {
            ListingSort::NAME_ASC => $products->orderBy('catalog_products.name')->orderBy('catalog_products.id'),
            default => $products->orderByDesc('catalog_products.timeline_at')->orderByDesc('catalog_products.id'),
        };

        return $products;
    }

    /**
     * HP-4: the first `$limit` cards of a source for the home page's product grid: `newest`, or the products of ONE category (with its shown descendants, as the category page), brand, tag or season. The SAME
     * query and the SAME card read as listing() (visibility, in-stock rule, batched assets / variations / stock / prices), but no page and no count query. An unknown source, a missing entity, a category that
     * is not shown: no cards. `$sort` is one of the storefront's offered sorts (anything else is `newest`).
     *
     * @return list<ProductCard>
     */
    public function homeProducts(string $kind, ?int $entity, string $sort, int $limit, string $sizes, ?ListingScope $subject = null, ?int $subjectId = null): array
    {
        // P3: `current` = the products of the PAGE's own category / brand / season / tag, read through the same sources below (the home page passes no subject: nothing)
        if ($kind === 'current') {
            if ($subject === null || $subjectId === null || $subjectId < 1) {
                return [];
            }

            $kind = $subject->value;
            $entity = $subjectId;
        }

        if ($kind !== 'newest' && $kind !== 'featured' && ($entity === null || $entity < 1)) {
            return [];
        }

        [$scope, $filters] = match ($kind) {
            'newest', 'featured' => [ListingScope::ALL, []],
            'category' => [ListingScope::CATEGORY, []],
            'brand' => [ListingScope::BRAND, []],
            'tag' => [ListingScope::TAG, []],
            'season' => [ListingScope::ALL, ['season' => [$entity]]],
            default => [null, []],
        };

        if ($scope === null) {
            return [];
        }

        $order = ListingSort::tryFrom($sort);
        $order = $order !== null && in_array($order, ListingSort::storefrontOptions(), true) ? $order : ListingSort::NEWEST;
        $query = ListingQuery::create($scope, $scope === ListingScope::ALL ? null : $entity, $order);

        $products = $this->listingProducts($query, $filters);
        $kind === 'featured' && $products->where('catalog_products.is_featured', true);

        return $this->cards($products->limit(max(1, min(24, $limit)))->get()->all(), $sizes);
    }

    /** CT-3 / CT-4: does the shop have a filterable attribute, or at least one brand / season / tag? Read from the first card of the page (an empty page: no). */
    private static function hasFilterable(?ProductModel $first): bool
    {
        if ($first === null) {
            return false;
        }

        foreach (['has_filters', 'has_brand_filter', 'has_season_filter', 'has_tag_filter'] as $column) {
            if ($first->getAttribute($column) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * CT-3: the ids of the values of ONE filter that give at least one product in this listing TOGETHER with the choices of the OTHER filters (this filter's own choices never narrow its own list). ONE
     * query: the union of the variation-level values (on a shown, in-stock variation that also satisfies the other variation-level filters) and the product-level values of the products that match
     * the other filters. Descendant categories, visibility and the «same variation» rule are those of listing().
     *
     * CT-4: for a built-in (`brand` | `season` | `tag`) the ids that give products among the visible products of the category that match the OTHER filters (the same visibility and scope as the listing; product-level
     * facts, so no variation or stock rule): ONE indexed query.
     *
     * @param  array<int|string, list<int>>  $filters  the whole selection
     * @return list<int>
     */
    public function filterValueIds(ListingQuery $query, array $filters, int|string $attribute): array
    {
        if (is_string($attribute)) {
            return $this->builtinValueIds($query, $filters, $attribute);
        }

        $others = array_diff_key($filters, [$attribute => 1]);
        [$builtinOthers, $attributeOthers] = FilterConstraint::split($others);

        $onVariations = DB::table('catalog_variation_attribute_values as vv')
            ->join('catalog_variations as v', 'v.id', '=', 'vv.variation_id')
            ->join('catalog_products', 'catalog_products.id', '=', 'v.product_id')
            ->where('vv.attribute_definition_id', $attribute)
            ->select('vv.attribute_value_id');
        $this->visibility->products($onVariations);
        $this->visibility->variations($onVariations, 'v');
        FilterConstraint::inStock($onVariations);
        $this->applyScope($onVariations, $query);
        FilterConstraint::onOneVariation($onVariations, $attributeOthers, 'catalog_products');
        FilterConstraint::builtin($onVariations, $builtinOthers);

        $onProducts = DB::table('catalog_product_attributes as pa')
            ->join('catalog_products', 'catalog_products.id', '=', 'pa.product_id')
            ->where('pa.attribute_definition_id', $attribute)
            ->where('pa.is_variation_axis', false)
            ->whereNotNull('pa.attribute_value_id')
            ->select('pa.attribute_value_id');
        $this->visibility->products($onProducts);
        $this->applyScope($onProducts, $query);
        FilterConstraint::apply($onProducts, $others);

        return array_map('intval', $onVariations->union($onProducts)->pluck('attribute_value_id')->all());
    }

    /** @return list<int> */
    private function builtinValueIds(ListingQuery $query, array $filters, string $kind): array
    {
        $products = DB::table('catalog_products');
        $this->visibility->products($products);
        $this->applyScope($products, $query);
        FilterConstraint::apply($products, $filters, $kind);

        $ids = match ($kind) {
            'brand' => $products->whereNotNull('catalog_products.brand_id')->distinct()->pluck('catalog_products.brand_id'),
            'season' => $products->whereNotNull('catalog_products.season_id')->distinct()->pluck('catalog_products.season_id'),
            'tag' => $products->join('catalog_product_tags as pt', 'pt.product_id', '=', 'catalog_products.id')->distinct()->pluck('pt.tag_id'),
            default => collect(),
        };

        return array_map('intval', $ids->all());
    }

    // ================================================================== internals: listing

    private function applyScope($products, ListingQuery $query): void
    {
        if ($query->scope === ListingScope::CATEGORY) {
            $id = (int) $query->scopeId;
            $ids = $this->categories->isShown($id) ? $this->categories->selfAndDescendantIds($id) : [];

            $products->whereIn('catalog_products.id', DB::table('catalog_product_categories')->whereIn('category_id', $ids)->select('product_id'));
        } elseif ($query->scope === ListingScope::BRAND) {
            $products->where('catalog_products.brand_id', (int) $query->scopeId);
        } elseif ($query->scope === ListingScope::TAG) {
            $products->whereIn('catalog_products.id', DB::table('catalog_product_tags')->where('tag_id', (int) $query->scopeId)->select('product_id'));
        } elseif ($query->scope === ListingScope::SEASON) {
            $products->where('catalog_products.season_id', (int) $query->scopeId);
        }
    }

    /**
     * The first customer-usable image of each product, as a correlated subquery INSIDE the page query (the same shape
     * as the sandbox's), but returning the media id so the page's assets can be loaded in ONE query afterwards.
     */
    private static function firstImageIdSubquery(): QueryBuilder
    {
        return self::usableImageIdSubquery(0);
    }

    /**
     * The SECOND customer-usable image of each product (PC-1): the same usable set and the same order as the first one,
     * one row further. A usable photo in position 2 is skipped like any other unusable one, so the second photo is the
     * second USABLE one. Same query as the first (no extra query per card).
     */
    private static function secondImageIdSubquery(): QueryBuilder
    {
        return self::usableImageIdSubquery(1);
    }

    /**
     * The media id of the usable image at `$position` (0 = first) of a product: an IMAGE that is READY and not missing
     * on disk (`missing_at`), in the merchant's order (sort_order, then media id). ONE rule for both photos.
     */
    private static function usableImageIdSubquery(int $position): QueryBuilder
    {
        $query = DB::table('catalog_product_media')
            ->join('catalog_media', 'catalog_media.id', '=', 'catalog_product_media.media_id')
            ->whereColumn('catalog_product_media.product_id', 'catalog_products.id');

        return self::usableMedia($query, [MediaType::IMAGE])
            ->orderBy('catalog_product_media.sort_order')
            ->orderBy('catalog_media.id')
            ->limit(1)
            ->offset($position)
            ->select('catalog_media.id');
    }

    /**
     * THE usable-media rule, for the cards' photos and the product page gallery alike: a file of one of `$types` that is READY and not missing
     * on disk (`missing_at`). Written once; both callers apply it to their own query.
     *
     * @template T of QueryBuilder|\Illuminate\Database\Eloquent\Builder
     * @param T $query
     * @param list<MediaType> $types
     * @return T
     */
    private static function usableMedia(QueryBuilder|\Illuminate\Database\Eloquent\Builder $query, array $types): QueryBuilder|\Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereIn('catalog_media.type', array_map(fn (MediaType $type): string => $type->value, $types))
            ->where('catalog_media.processing_status', ProcessingStatus::READY->value)
            ->whereNull('catalog_media.missing_at');
    }
    /**
     * @param array<int, ProductModel> $products
     * @return list<ProductCard>
     */
    private function cards(array $products, string $sizes = ImageSetBuilder::SIZES_CARD): array
    {
        if ($products === []) {
            return [];
        }

        $productIds = array_map(fn (ProductModel $p): string => (string) $p->id, $products);
        $mediaIds = array_values(array_unique(array_filter(array_merge(
            array_map(fn (ProductModel $p) => $p->getAttribute('first_media_id'), $products),
            array_map(fn (ProductModel $p) => $p->getAttribute('second_media_id'), $products),
        ))));

        $assets = $mediaIds === [] ? collect() : MediaAssetModel::query()->whereIn('id', $mediaIds)->get()->keyBy('id');

        $shown = $this->visibility->variations(VariationModel::query())
            ->whereIn('catalog_variations.product_id', $productIds)
            ->get(['catalog_variations.id', 'catalog_variations.product_id']);

        $variationIdsByProduct = [];

        foreach ($shown as $variation) {
            $variationIdsByProduct[(string) $variation->product_id][] = (string) $variation->id;
        }

        $stock = $this->stockByVariation(array_merge([], ...array_values($variationIdsByProduct)));
        $allVariationIds = array_merge([], ...array_values($variationIdsByProduct));
        $quotes = $this->quotesByVariation($allVariationIds);

        $cards = [];

        foreach ($products as $product) {
            $id = (string) $product->id;
            $mediaId = $product->getAttribute('first_media_id');
            $asset = $mediaId === null ? null : $assets->get($mediaId);
            $secondId = $product->getAttribute('second_media_id');
            $second = $secondId === null ? null : $assets->get($secondId);

            $cards[] = new ProductCard(
                id: $id,
                slug: (string) $product->slug,
                url: $this->urls->product((string) $product->slug),
                name: (string) $product->name,
                brand: $this->brandOf($product),
                image: $asset === null ? null : $this->images->build($asset, (string) $product->name, $sizes),
                secondImage: $second === null ? null : $this->images->build($second, (string) $product->name, $sizes),
                price: $this->productPrice($variationIdsByProduct[$id] ?? [], $quotes),
                badges: [],
                inStock: array_filter(
                    array_map(fn (string $variationId): int => $stock[$variationId] ?? 0, $variationIdsByProduct[$id] ?? []),
                    fn (int $quantity): bool => $quantity > 0,
                ) !== [],
            );
        }

        return $cards;
    }

    /**
     * ONE price rule for a card and a product page: the exact block of the quotes of the product's SHOWN variations
     * (a variation with no price is absent), or null when none is priced.
     *
     * @param list<string> $variationIds the product's shown variations
     * @param array<string, PriceQuote> $quotes quotes by variation id (any superset)
     */
    private function productPrice(array $variationIds, array $quotes): ?PriceBlock
    {
        $own = [];

        foreach ($variationIds as $variationId) {
            if (isset($quotes[$variationId])) {
                $own[$variationId] = $quotes[$variationId];
            }
        }

        return $own === [] ? null : $this->priceBlock($own);
    }

    // ================================================================== internals: product page

    /**
     * The usable images (first = main) and the gallery items (usable images AND videos, in media order), from ONE query (assets carry their
     * variants). The usable rule is the card's own (usableMedia): a READY, not-missing file. `images` keep their historic alt text (the JSON
     * contract); the gallery items carry the alt of the gallery markup (the image's alt, else the product name + " — снимка N").
     *
     * @return array{images: list<ImageSet>, items: list<GalleryItem>}
     */
    private function gallery(int $productId, string $name): array
    {
        $query = MediaAssetModel::query()
            ->join('catalog_product_media', 'catalog_product_media.media_id', '=', 'catalog_media.id')
            ->where('catalog_product_media.product_id', $productId)
            ->orderBy('catalog_product_media.sort_order')
            ->orderBy('catalog_media.id');

        $assets = self::usableMedia($query, [MediaType::IMAGE, MediaType::VIDEO])->get(['catalog_media.*']);

        $images = [];
        $items = [];
        $position = 0;
        $imageIndex = 0;

        foreach ($assets as $asset) {
            if ($asset->type === MediaType::VIDEO->value) {
                $items[] = new GalleryItem(
                    kind: 'video',
                    videoUrl: $this->images->fileUrl($asset),
                    videoMime: (string) ($asset->mime ?? ''),
                );

                continue;
            }

            // the position among the IMAGES (a video does not count: the numbers of the alt texts are the photos')
            $position++;

            $set = $this->images->build($asset, $position === 1 ? $name : "{$name} — {$position}", ImageSetBuilder::SIZES_GALLERY);

            if ($set === null) {
                continue;
            }

            $images[] = $set;
            $big = $this->images->build($asset, $name.' — '.__('storefront.gallery_photo', ['n' => $position]), ImageSetBuilder::SIZES_GALLERY);
            $thumb = $set->srcset[0];
            $items[] = new GalleryItem(
                kind: 'image',
                imageIndex: $imageIndex++,
                image: $big,
                thumbUrl: $thumb['url'],
                thumbWidth: $thumb['width'],
                thumbHeight: max(1, (int) round($thumb['width'] * $set->height / $set->width)),
                largeUrl: $set->srcset[count($set->srcset) - 1]['url'],
            );
        }

        return ['images' => $images, 'items' => $items];
    }
    /** @return list<Breadcrumb> the deepest shown category's trail, then the product */
    private function productBreadcrumbs(int $productId, string $name, string $slug): array
    {
        $categoryIds = DB::table('catalog_product_categories')->where('product_id', $productId)->pluck('category_id')->map(fn ($id): int => (int) $id)->all();

        $best = null;
        $bestDepth = -1;

        foreach ($categoryIds as $id) {
            if (! $this->categories->isShown($id)) {
                continue;
            }

            $depth = $this->categories->depth($id);

            if ($depth > $bestDepth || ($depth === $bestDepth && $id < $best)) {
                $best = $id;
                $bestDepth = $depth;
            }
        }

        $crumbs = $best === null ? [] : $this->categories->breadcrumbs($best);
        $crumbs[] = new Breadcrumb($name, $this->urls->product($slug));

        return $crumbs;
    }

    // ================================================================== internals: shared

    /** @return array{name: string, slug: string}|null */
    private function brandOf(ProductModel $product): ?array
    {
        return $product->brand === null ? null : ['name' => (string) $product->brand->name, 'slug' => (string) $product->brand->slug];
    }

    /**
     * variation id => quantity, in ONE read. A variation with no stock row has no stock (0).
     *
     * @param list<string> $variationIds
     * @return array<string, int>
     */
    private function stockByVariation(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $stock = [];

        foreach (StockLevelModel::query()->whereIn('variation_id', $variationIds)->get(['variation_id', 'quantity']) as $level) {
            $stock[(string) $level->variation_id] = (int) $level->quantity;
        }

        return $stock;
    }

    /**
     * variation id => PriceQuote for the given variations, through the existing batched resolvers (the same pair
     * ProductPriceRangeProvider uses): a variation with no price is simply absent.
     *
     * @param list<string> $variationIds
     * @return array<string, PriceQuote>
     */
    private function quotesByVariation(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $contexts = [];

        foreach ($this->scopeResolver->forVariations($variationIds) as $variationId => $data) {
            $contexts[] = new PriceContext(
                priceableId: (string) $variationId,
                quantity: 1,
                currency: DefaultCurrency::get()->code(),
                productId: $data['productId'],
                matchingScopeReferenceIds: $data['matchingScopeReferenceIds'],
            );
        }

        return $contexts === [] ? [] : $this->priceResolver->resolveQuotes($contexts);
    }

    /**
     * The exact block of a set of quotes keyed by variation id (non-empty): lowest and highest final price.
     *
     * @param array<string, PriceQuote> $quotes
     */
    private function priceBlock(array $quotes): PriceBlock
    {
        $range = PriceRange::fromQuotes($quotes);
        $cheapest = $range->lowestFinalQuote();
        $finals = array_map(fn (PriceQuote $q): int => $q->final->gross()->minorValue(), $quotes);

        return new PriceBlock(
            fromMinor: $cheapest->final->gross()->minorValue(),
            toMinor: max($finals),
            currency: $cheapest->final->currency()->code(),
            regularFromMinor: $cheapest->isDiscounted() ? $cheapest->regular->gross()->minorValue() : null,
        );
    }
}
