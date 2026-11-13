<?php

namespace App\Storefront\Conditions;

use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\ReadModels\PriceBlock;
use App\Storefront\ReadModels\ProductPage;
use Illuminate\Support\Facades\DB;

/**
 * What the product predicates (ProductConditions) look at: the product of the page, built ONCE per request from what the page already has (its id, its shown variations, the price block it prints).
 * The facts that are not on the page (categories, tags, season, attribute values) are read LAZILY, one small query each, and only when a predicate asks: a page with no conditioned block and no
 * `@productIs` costs nothing. Everything read is public catalog data.
 */
final class ProductContext
{
    /** @var list<int>|null */
    private ?array $categoryIds = null;

    /** @var list<int>|null */
    private ?array $tagIds = null;

    /** @var list<int>|null */
    private ?array $collectionIds = null;

    private int|false|null $seasonId = false;

    /** @var array<int, list<int>>|null attribute id => value ids */
    private ?array $attributeValues = null;

    /** @var array<int, ?int>|null category id => parent id (every category: a few hundred rows at most), shared by the contexts of the request */
    private static ?array $parents = null;

    /** @param  list<int>  $variationIds  the variations the page shows */
    public function __construct(
        public readonly int $productId,
        public readonly array $variationIds,
        public readonly ?PriceBlock $price,
        /** P4c: the product's brand id, from the product row the page already loaded (null = no brand): the `in_brand` predicate costs no query */
        public readonly ?int $brandId = null,
    ) {
    }

    /**
     * P7-2: a context whose lazy facts are ALREADY known (the card badges read them for a whole listing page at once, then ask the very same predicates of ProductConditions per product): nothing is read afterwards. The
     * attribute values are not prefilled; a condition that needs them is not offered to a badge.
     *
     * @param  list<int>  $categoryIds  with their ancestors
     * @param  list<int>  $tagIds
     * @param  list<int>  $collectionIds
     */
    public static function prefilled(int $productId, ?PriceBlock $price, ?int $brandId, array $categoryIds, array $tagIds, array $collectionIds, ?int $seasonId): self
    {
        $context = new self($productId, [], $price, $brandId);
        $context->categoryIds = $categoryIds;
        $context->tagIds = $tagIds;
        $context->collectionIds = $collectionIds;
        $context->seasonId = $seasonId;

        return $context;
    }

    public static function fromPage(ProductPage $page): self
    {
        return new self((int) $page->id, array_map(static fn ($variation): int => (int) $variation->id, $page->variations), $page->price, $page->brandId);
    }

    /** Forgets what is shared between contexts (tests, long-lived workers). */
    public static function forgetShared(): void
    {
        self::$parents = null;
    }

    /** Is the product on sale? The very fact the price block prints (a struck-through regular price): the sale state is the price's own, dates and price lists included. */
    public function onSale(): bool
    {
        return $this->price !== null && $this->price->regularFromMinor !== null;
    }

    /** The product's categories AND all their ancestors: a product in «Dresses» is in «Clothing» too. @return list<int> */
    public function categoryIds(): array
    {
        if ($this->categoryIds !== null) {
            return $this->categoryIds;
        }

        $own = DB::table('catalog_product_categories')->where('product_id', $this->productId)->pluck('category_id')->map(static fn ($id): int => (int) $id)->all();

        return $this->categoryIds = self::withAncestors($own);
    }

    /** The categories AND all their ancestors (the tree is read once per request: every category, a few hundred rows at most). @param list<int> $own @return list<int> */
    public static function withAncestors(array $own): array
    {
        self::$parents ??= DB::table('catalog_categories')->pluck('parent_id', 'id')->map(static fn ($parent): ?int => $parent === null ? null : (int) $parent)->all();
        $all = [];

        foreach ($own as $id) {
            for ($current = $id, $guard = 0; $current !== null && ! isset($all[$current]) && $guard < 50; $current = self::$parents[$current] ?? null, $guard++) {
                $all[$current] = $current;
            }
        }

        return array_values($all);
    }

    /** @return list<int> */
    public function tagIds(): array
    {
        return $this->tagIds ??= DB::table('catalog_product_tags')->where('product_id', $this->productId)->pluck('tag_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * P6e-3: the product's collections. ONE read, only when a conditioned block asks (a page without one pays nothing). The switch rides INSIDE the statement (`CatalogSubjectKind::gated`): while the collections
     * are OFF the read is EMPTY, so an `in_collection` condition is false and the block is hidden: no separate settings query.
     *
     * @return list<int>
     */
    public function collectionIds(): array
    {
        return $this->collectionIds ??= CatalogSubjectKind::COLLECTION->gated(DB::table('catalog_product_collections')->where('product_id', $this->productId))->pluck('collection_id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function seasonId(): ?int
    {
        if ($this->seasonId === false) {
            $id = DB::table('catalog_products')->where('id', $this->productId)->value('season_id');
            $this->seasonId = $id === null ? null : (int) $id;
        }

        return $this->seasonId;
    }

    /** The attribute values of the variations the page shows. @return array<int, list<int>> attribute id => value ids */
    public function attributeValues(): array
    {
        if ($this->attributeValues !== null) {
            return $this->attributeValues;
        }

        $out = [];

        if ($this->variationIds !== []) {
            foreach (DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $this->variationIds)->get(['attribute_definition_id', 'attribute_value_id']) as $row) {
                $out[(int) $row->attribute_definition_id][(int) $row->attribute_value_id] = (int) $row->attribute_value_id;
            }
        }

        return $this->attributeValues = array_map('array_values', $out);
    }
}
