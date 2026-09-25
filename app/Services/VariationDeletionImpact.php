<?php

namespace App\Services;

use EasyCo\Catalog\Exceptions\VariationNotDeletableException;

/**
 * The read-only report behind the variation-delete confirmation —
 * catalog-domain-design.md §3.19.3/§3.19.8 A. Assembled entirely by
 * App\Services\CatalogDeletion, never by a Filament page: the counts and
 * the refusal decision come from the same place the delete itself
 * re-checks, so a page cannot render "deletable" while the delete refuses,
 * or the other way round.
 *
 * `refusal` IS THE REASON, NOT A BOOLEAN — a nullable
 * VariationNotDeletableException whose OWN message is what the modal
 * shows verbatim (§3.19.8 D). `isDeletable()` is exactly
 * `refusal === null`, so there is one source of truth for "can this be
 * deleted" rather than a flag and a reason that could disagree.
 *
 * EVERY COUNT IS "WHAT THE DELETE WILL REMOVE", computed with the same raw
 * reads the delete uses — including rows another model would hide behind a
 * SoftDeletes scope, because those are rows the delete genuinely removes.
 * `convertedCartLineCount` is a subset of `cartLineCount` (its own carts
 * already became orders), not a separate bucket.
 */
final class VariationDeletionImpact
{
    /**
     * @param array<int, array{name: string, value: string}> $attributes
     *   The variation's sold combination, in definition-id order (the same
     *   deterministic order the §3.13 sale-line snapshot uses).
     */
    public function __construct(
        public readonly string $variationId,
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $sku,
        public readonly ?string $barcode,
        public readonly string $status,
        public readonly string $attributeSignature,
        public readonly array $attributes,
        public readonly int $saleLineCount,
        public readonly int $stockQuantity,
        public readonly int $cartLineCount,
        public readonly int $convertedCartLineCount,
        public readonly int $priceListItemCount,
        public readonly int $costRowCount,
        public readonly int $mediaCount,
        public readonly ?VariationNotDeletableException $refusal,
    ) {
    }

    public function isDeletable(): bool
    {
        return $this->refusal === null;
    }
}
