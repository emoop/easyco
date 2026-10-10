<?php

namespace App\Storefront\Reader;

use App\Services\CatalogScopeResolver;
use App\Services\ProductPriceRangeProvider;
use App\Storefront\Exceptions\ListingOptionNotAvailable;
use App\Storefront\ReadModels\Breadcrumb;
use App\Storefront\ReadModels\CategoryNode;
use App\Storefront\ReadModels\CategoryPage;
use App\Storefront\ReadModels\ImageSet;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\ListingScope;
use App\Storefront\ReadModels\ListingSort;
use App\Storefront\ReadModels\PriceBlock;
use App\Storefront\ReadModels\ProductCard;
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
 *                   + count (1) + the page's assets (1) + shown variations (1) + stock (1) + prices (ProductPriceRangeProvider)
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
        private readonly ProductPriceRangeProvider $priceRanges,
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
            ->select(['id', 'type', 'name', 'slug', 'short_description', 'description', 'brand_id'])
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
                ->get(['vav.variation_id', 'def.id as definition_id', 'def.name as definition_name', 'val.value as value'])
                ->all();

        $stock = $this->stockByVariation($variationIds);
        $quotes = $this->quotesByVariation($variationIds);

        $attributesOf = [];
        $optionValues = [];
        $optionNames = [];

        foreach ($attributeRows as $row) {
            $attributesOf[(string) $row->variation_id][] = ['name' => (string) $row->definition_name, 'value' => (string) $row->value];
            $optionNames[(int) $row->definition_id] = (string) $row->definition_name;
            $optionValues[(int) $row->definition_id][(string) $row->value] = true;
        }

        $options = [];

        foreach ($optionNames as $definitionId => $name) {
            $options[] = ['name' => $name, 'values' => array_map('strval', array_keys($optionValues[$definitionId]))];
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
                price: isset($quotes[$id]) ? $this->priceBlock([$id => $quotes[$id]]) : null,
                inStock: ($stock[$id] ?? 0) > 0,
                purchasable: $variation->status === StorefrontVisibility::VARIATION_STATUS->value && (bool) $variation->is_purchasable,
            );
        }

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
            images: $this->gallery($productId, (string) $product->name),
            price: $quotes === [] ? null : $this->priceBlock($quotes),
            inStock: array_filter($stock, fn (int $quantity): bool => $quantity > 0) !== [],
            options: $options,
            variations: $views,
            badges: [],
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
        );
    }

    // ================================================================== listing

    /**
     * @throws ListingOptionNotAvailable for a price sort or a price filter (they need S7's price_from_minor)
     */
    public function listing(ListingQuery $query): ListingPage
    {
        if ($query->hasPriceFilter() || $query->sort === ListingSort::PRICE_ASC || $query->sort === ListingSort::PRICE_DESC) {
            throw new ListingOptionNotAvailable('Price filtering and price sorting arrive with the storefront search stage (S7).');
        }

        $products = $this->visibility->products(ProductModel::query())
            ->select(['catalog_products.id', 'catalog_products.slug', 'catalog_products.name', 'catalog_products.brand_id'])
            ->addSelect(['first_media_id' => self::firstImageIdSubquery()])
            ->with('brand:id,name,slug');

        $this->applyScope($products, $query);

        match ($query->sort) {
            ListingSort::NAME_ASC => $products->orderBy('catalog_products.name')->orderBy('catalog_products.id'),
            default => $products->orderByDesc('catalog_products.timeline_at')->orderByDesc('catalog_products.id'),
        };

        $paginator = $products->paginate($query->perPage->value, ['*'], 'page', $query->page);

        return new ListingPage(
            query: $query->toArray(),
            items: $this->cards($paginator->items()),
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: $paginator->lastPage(),
            facets: [],
        );
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
        }
    }

    /**
     * The first customer-usable image of each product, as a correlated subquery INSIDE the page query (the same shape
     * as the sandbox's), but returning the media id so the page's assets can be loaded in ONE query afterwards. Only an
     * IMAGE whose processing is READY counts: a pending or failed asset has no files yet.
     */
    private static function firstImageIdSubquery(): QueryBuilder
    {
        return DB::table('catalog_product_media')
            ->join('catalog_media', 'catalog_media.id', '=', 'catalog_product_media.media_id')
            ->whereColumn('catalog_product_media.product_id', 'catalog_products.id')
            ->where('catalog_media.type', MediaType::IMAGE->value)
            ->where('catalog_media.processing_status', ProcessingStatus::READY->value)
            ->orderBy('catalog_product_media.sort_order')
            ->orderBy('catalog_media.id')
            ->limit(1)
            ->select('catalog_media.id');
    }

    /**
     * @param array<int, ProductModel> $products
     * @return list<ProductCard>
     */
    private function cards(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $productIds = array_map(fn (ProductModel $p): string => (string) $p->id, $products);
        $mediaIds = array_values(array_unique(array_filter(array_map(fn (ProductModel $p) => $p->getAttribute('first_media_id'), $products))));

        $assets = $mediaIds === [] ? collect() : MediaAssetModel::query()->whereIn('id', $mediaIds)->get()->keyBy('id');

        $shown = $this->visibility->variations(VariationModel::query())
            ->whereIn('catalog_variations.product_id', $productIds)
            ->get(['catalog_variations.id', 'catalog_variations.product_id']);

        $variationIdsByProduct = [];

        foreach ($shown as $variation) {
            $variationIdsByProduct[(string) $variation->product_id][] = (string) $variation->id;
        }

        $stock = $this->stockByVariation(array_merge([], ...array_values($variationIdsByProduct)));
        $ranges = $this->priceRanges->forProducts($productIds);

        $cards = [];

        foreach ($products as $product) {
            $id = (string) $product->id;
            $mediaId = $product->getAttribute('first_media_id');
            $asset = $mediaId === null ? null : $assets->get($mediaId);

            $cards[] = new ProductCard(
                id: $id,
                slug: (string) $product->slug,
                url: $this->urls->product((string) $product->slug),
                name: (string) $product->name,
                brand: $this->brandOf($product),
                image: $asset === null ? null : $this->images->build($asset, (string) $product->name, ImageSetBuilder::SIZES_CARD),
                price: $this->cardPrice($ranges[$id] ?? null),
                badges: [],
                inStock: array_filter(
                    array_map(fn (string $variationId): int => $stock[$variationId] ?? 0, $variationIdsByProduct[$id] ?? []),
                    fn (int $quantity): bool => $quantity > 0,
                ) !== [],
            );
        }

        return $cards;
    }

    private function cardPrice(?PriceRange $range): ?PriceBlock
    {
        if ($range === null || $range->isEmpty()) {
            return null;
        }

        $cheapest = $range->lowestFinalQuote();
        $from = $cheapest->final->gross()->minorValue();

        return new PriceBlock(
            fromMinor: $from,
            toMinor: $range->hasUniformFinalPrice() ? $from : null,
            currency: $cheapest->final->currency()->code(),
            regularFromMinor: $cheapest->isDiscounted() ? $cheapest->regular->gross()->minorValue() : null,
        );
    }

    // ================================================================== internals: product page

    /** @return list<ImageSet> the READY gallery images, first = main, in ONE query (assets carry their variants) */
    private function gallery(int $productId, string $name): array
    {
        $assets = MediaAssetModel::query()
            ->join('catalog_product_media', 'catalog_product_media.media_id', '=', 'catalog_media.id')
            ->where('catalog_product_media.product_id', $productId)
            ->where('catalog_media.type', MediaType::IMAGE->value)
            ->where('catalog_media.processing_status', ProcessingStatus::READY->value)
            ->orderBy('catalog_product_media.sort_order')
            ->orderBy('catalog_media.id')
            ->get(['catalog_media.*']);

        $images = [];
        $position = 0;

        foreach ($assets as $asset) {
            $position++;
            $set = $this->images->build($asset, $position === 1 ? $name : "{$name} — {$position}", ImageSetBuilder::SIZES_GALLERY);

            if ($set !== null) {
                $images[] = $set;
            }
        }

        return $images;
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
