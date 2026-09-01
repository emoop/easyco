<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;

/**
 * Assembles the Catalog data EasyCo\Pricing\Contracts\PriceContext needs
 * for scope matching (`productId` + `matchingScopeReferenceIds`, keyed
 * by EasyCo\Pricing\Enums\PriceListScopeType's 'brand'/'category'/
 * 'tag'/'attribute_value' values — see that contract's own docblock for
 * why this assembly must happen in the caller, not inside Pricing:
 * Pricing must never depend on Catalog directly, CLAUDE.md rule 9).
 *
 * Deliberately not tied to Cart specifically — a small, reusable,
 * app-layer service any future caller (Promotions' own eventual Cart
 * integration, an Orders checkout flow, ...) can call the same way.
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
     * @return array{productId: ?string, matchingScopeReferenceIds: array<string, string[]>}
     */
    public function forVariation(string $variationId): array
    {
        $variation = $this->variations->findById($variationId);

        if ($variation === null) {
            // A missing variation is the caller's problem — both existing
            // CartController call sites already handle a missing/
            // unpurchasable variation before ever reaching pricing.
            return ['productId' => null, 'matchingScopeReferenceIds' => []];
        }

        $productId = $variation->productId();
        $product = $this->products->findById($productId);

        $matchingScopeReferenceIds = [];

        $brandId = $product?->brandId();
        if ($brandId !== null) {
            $matchingScopeReferenceIds['brand'] = [$brandId];
        }

        $categoryIds = array_map(
            static fn (ProductCategory $pc) => $pc->categoryId(),
            $this->productCategories->findByProductId($productId)
        );
        if ($categoryIds !== []) {
            $matchingScopeReferenceIds['category'] = $categoryIds;
        }

        $tagIds = array_map(
            static fn (ProductTag $pt) => $pt->tagId(),
            $this->productTags->findByProductId($productId)
        );
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

        return [
            'productId' => $productId,
            'matchingScopeReferenceIds' => $matchingScopeReferenceIds,
        ];
    }
}
