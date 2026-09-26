<?php

namespace App\Services;

use EasyCo\Catalog\Exceptions\ProductNotDeletableException;

/**
 * The read-only report behind the product-delete confirmation —
 * catalog-domain-design.md §3.19.3/§3.19.8 B, and the return type of
 * CatalogDeletion::impactForProduct(). Assembled entirely by that service,
 * never by a Filament page: the counts and the refusal decision come from
 * the same place the delete itself re-checks, so a page cannot render
 * "deletable" while the delete refuses.
 *
 * `refusal` IS THE REASON, NOT A BOOLEAN, exactly like VariationDeletionImpact's
 * — a nullable ProductNotDeletableException, and `isDeletable()` is exactly
 * `refusal === null`, so there is one source of truth for "can this go".
 *
 * `variations` IS THE WHOLE SET THE DELETE WILL TAKE — UNIVERSAL, live
 * STANDARD, ARCHIVED and soft-deleted alike, in ascending
 * catalog_variations.id — each carrying its OWN VariationDeletionImpact so
 * the modal can show a per-variation verdict and the §3.19.10 snapshot can
 * record each row's identity, prices, costs and stock. When `isDeletable()`
 * is true every one of them has `refusal === null` (that is G-D3: one
 * blocked variation refuses the whole operation), so
 * deletableVariations()/blockedVariations() split a set that is in practice
 * all-delete or the operation is refused.
 *
 * EVERY COUNT IS "WHAT THE DELETE WILL REMOVE", totals across the whole
 * scope: the variation-scoped counts are the sum over `variations`, and the
 * `product*` ones are the product-scope rows the variation loop cannot
 * reach (`pricing_price_list_items` with a PRODUCT target,
 * `pricing_price_list_scopes`, `promotion_scopes`, `catalog_product_media`).
 * `mediaCount` includes the product's own pivots; the `catalog_media` rows
 * and the files are NOT deleted (§3.19.7) and never counted here.
 */
final class ProductDeletionImpact
{
    /**
     * @param list<VariationDeletionImpact> $variations
     *   Ascending catalog_variations.id, including soft-deleted rows.
     * @param int $productPriceListItemCount The product-target subset of priceListItemCount.
     * @param int $productMediaCount The catalog_product_media subset of mediaCount.
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $baseSku,
        public readonly string $slug,
        public readonly string $status,
        public readonly array $variations,
        public readonly int $cartLineCount,
        public readonly int $convertedCartLineCount,
        public readonly int $priceListItemCount,
        public readonly int $productPriceListItemCount,
        public readonly int $priceListScopeCount,
        public readonly int $promotionScopeCount,
        public readonly int $costRowCount,
        public readonly int $mediaCount,
        public readonly int $productMediaCount,
        public readonly ?ProductNotDeletableException $refusal,
    ) {
    }

    public function isDeletable(): bool
    {
        return $this->refusal === null;
    }

    /** @return list<VariationDeletionImpact> */
    public function deletableVariations(): array
    {
        return array_values(array_filter(
            $this->variations,
            static fn (VariationDeletionImpact $variation): bool => $variation->isDeletable(),
        ));
    }

    /** @return list<VariationDeletionImpact> */
    public function blockedVariations(): array
    {
        return array_values(array_filter(
            $this->variations,
            static fn (VariationDeletionImpact $variation): bool => ! $variation->isDeletable(),
        ));
    }

    public function variationCount(): int
    {
        return count($this->variations);
    }
}
