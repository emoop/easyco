<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Variation;

/**
 * Assembles the Catalog data EasyCo\Pricing\Contracts\PriceContext needs
 * for scope matching (`productId` + `matchingScopeReferenceIds`, keyed
 * by EasyCo\Pricing\Enums\PriceListScopeType's 'brand'/'category'/
 * 'tag'/'attribute_value' values — see that contract's own docblock for
 * why this assembly must happen in the caller, not inside Pricing:
 * Pricing must never depend on Catalog directly, CLAUDE.md rule 9).
 *
 * ALSO returns productName/sku — resolved from the same $variation/
 * $product this method already loads for scope matching, no extra query
 * — for CheckoutOrchestrator to snapshot onto a SALE-type SaleLine per
 * operational-sales-domain-design.md §3.12. A missing variation returns
 * both as null, same as productId above; CheckoutOrchestrator's own
 * callers already handle a missing/unpurchasable variation before ever
 * reaching pricing.
 *
 * Deliberately not tied to Cart specifically — a small, reusable,
 * app-layer service any future caller (Promotions' own eventual Cart
 * integration, an Orders checkout flow, App\Services\ProductPriceRangeProvider's
 * batched product-listing use, ...) can call the same way.
 *
 * forVariation() IS NOW A THIN WRAPPER over forVariations() — ONE
 * implementation of the mapping rules (brand from the product,
 * category/tag from the product's pivots, attribute_value from each
 * variation's own attributeAssignments(), the string casts, the
 * "only add a non-empty dimension" rule), never two to keep in sync.
 */
class CatalogScopeResolver
{
    public function __construct(
        private readonly VariationRepository $variations,
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $productCategories,
        private readonly ProductTagRepository $productTags,
    ) {
    }

    /**
     * @return array{productId: ?string, matchingScopeReferenceIds: array<string, string[]>, productName: ?string, sku: ?string}
     */
    public function forVariation(string $variationId): array
    {
        $nullShape = ['productId' => null, 'matchingScopeReferenceIds' => [], 'productName' => null, 'sku' => null];

        return $this->forVariations([$variationId])[$variationId] ?? $nullShape;
    }

    /**
     * Batched form — one call resolving the same shape as forVariation()
     * for many variations at once, at a bounded query count regardless
     * of how many ids are given: one VariationRepository::findByIds()
     * (itself ~2 queries via its own reused loadAttributeAssignments()),
     * one ProductRepository::findBrandIdsByProductIds(), one
     * ProductCategoryRepository::findByProductIds(), one
     * ProductTagRepository::findByProductIds() — never one query per
     * variation/product.
     *
     * A variationId that does not resolve to a real row is simply
     * OMITTED from the returned array (not present as a null-shape
     * entry) — forVariation() is what turns a single miss back into the
     * null-shape for its own callers, who expect exactly one entry back
     * for the one id they asked about.
     *
     * @param string[] $variationIds
     * @return array<string, array{productId: ?string, matchingScopeReferenceIds: array<string, string[]>, productName: ?string, sku: ?string}> keyed by variationId
     */
    public function forVariations(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $variationsById = $this->variations->findByIds($variationIds);

        if ($variationsById === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_map(
            static fn (Variation $variation): string => $variation->productId(),
            $variationsById
        )));

        $brandIdsByProductId = $this->products->findBrandIdsByProductIds($productIds);

        // productName has no batched ProductRepository method of its own
        // — deliberately not adding one to the domain CONTRACT beyond
        // this task's explicitly authorized batched reads
        // (findByIds()/findBrandIdsByProductIds()/findByProductIds() x2).
        // A direct, read-only ProductModel::whereIn() query instead —
        // ONE query regardless of how many distinct products are in the
        // batch, the same established precedent every other read-only,
        // non-invariant-bearing lookup in this codebase already uses
        // (ProductResource's own "every Resource's table/listing reads
        // directly through its Eloquent model" docblock;
        // findBrandIdsByProductIds()'s own Eloquent implementation reads
        // the identical column set this way). A findById() loop here —
        // tried first — measurably broke this method's own boundedness
        // contract (a real, failing query-count test caught it: 26
        // queries for 5 products vs. 86 for 25, not equal), so it is not
        // used.
        $productNamesById = ProductModel::whereIn('id', $productIds)->pluck('name', 'id')->all();

        $categoryIdsByProductId = $this->groupByProductId(
            $this->productCategories->findByProductIds($productIds),
            static fn (ProductCategory $pc): string => $pc->productId(),
            static fn (ProductCategory $pc): string => $pc->categoryId()
        );

        $tagIdsByProductId = $this->groupByProductId(
            $this->productTags->findByProductIds($productIds),
            static fn (ProductTag $pt): string => $pt->productId(),
            static fn (ProductTag $pt): string => $pt->tagId()
        );

        $result = [];
        foreach ($variationsById as $variationId => $variation) {
            $productId = $variation->productId();

            $matchingScopeReferenceIds = [];

            $brandId = $brandIdsByProductId[$productId] ?? null;
            if ($brandId !== null) {
                $matchingScopeReferenceIds['brand'] = [$brandId];
            }

            $categoryIds = $categoryIdsByProductId[$productId] ?? [];
            if ($categoryIds !== []) {
                $matchingScopeReferenceIds['category'] = $categoryIds;
            }

            $tagIds = $tagIdsByProductId[$productId] ?? [];
            if ($tagIds !== []) {
                $matchingScopeReferenceIds['tag'] = $tagIds;
            }

            // attributeAssignments() values are typed int|string (see
            // Variation's own constructor docblock) — cast explicitly so
            // this always matches matchingScopeReferenceIds' string[]
            // contract regardless of what the underlying storage driver
            // happened to hand back.
            $attributeValueIds = array_map(strval(...), array_values($variation->attributeAssignments()));
            if ($attributeValueIds !== []) {
                $matchingScopeReferenceIds['attribute_value'] = $attributeValueIds;
            }

            $result[$variationId] = [
                'productId' => $productId,
                'matchingScopeReferenceIds' => $matchingScopeReferenceIds,
                'productName' => $productNamesById[$productId] ?? null,
                'sku' => $variation->sku(),
            ];
        }

        return $result;
    }

    /**
     * @template T
     * @param T[] $rows
     * @param callable(T): string $productIdOf
     * @param callable(T): string $referenceIdOf
     * @return array<string, string[]> keyed by productId
     */
    private function groupByProductId(array $rows, callable $productIdOf, callable $referenceIdOf): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$productIdOf($row)][] = $referenceIdOf($row);
        }

        return $grouped;
    }
}
