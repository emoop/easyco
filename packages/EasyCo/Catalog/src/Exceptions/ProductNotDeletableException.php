<?php

namespace EasyCo\Catalog\Exceptions;

use EasyCo\Catalog\Enums\ProductDeletionRefusal;
use EasyCo\Catalog\Enums\VariationDeletionRefusal;
use RuntimeException;

/**
 * Thrown when a product cannot be hard-deleted — the product-level half of
 * catalog-domain-design.md §3.19 (G-D3, §3.19.8 B/D).
 *
 * IT DELEGATES THE VARIATION-LEVEL REASONS RATHER THAN RESTATING THEM: a
 * product may be refused because one of its variations has history or
 * non-zero stock, and that is exactly VariationNotDeletableException's own
 * fact. Rewording it here would give one rule two sentences — so
 * `blockingVariations` carries, per offending variation, the variation's
 * SKU, the number that is not zero (sale lines, or quantity on hand) and
 * its own `VariationDeletionRefusal`. `reason` therefore distinguishes only
 * the two PRODUCT-level cases: not archived, or blocked by variations.
 *
 * A REASON, NOT JUST A SENTENCE: the merchant-facing text is localised by
 * the application layer from `reason` + `productName` + those facts
 * (App\Services\ProductDeletionRefusalMessage, rendering
 * `products.deletion.product_refusal.*`), so a Bulgarian merchant reads
 * Bulgarian. The exception's OWN message stays English on purpose, like
 * VariationNotDeletableException's: it is what logs, exception dumps and
 * support tickets carry.
 */
final class ProductNotDeletableException extends RuntimeException
{
    /**
     * @param list<array{sku: string, count: int, reason: VariationDeletionRefusal}> $blockingVariations
     *   Empty for NOT_ARCHIVED; one entry per offending variation otherwise,
     *   in the product's own variation order.
     */
    private function __construct(
        string $message,
        public readonly ProductDeletionRefusal $reason,
        public readonly string $productName,
        public readonly array $blockingVariations = [],
    ) {
        parent::__construct($message);
    }

    public static function becauseNotArchived(string $productName): self
    {
        return new self(
            "Product \"{$productName}\" is not archived and cannot be deleted. Archive it first.",
            ProductDeletionRefusal::NOT_ARCHIVED,
            $productName,
        );
    }

    /**
     * @param list<array{sku: string, count: int, reason: VariationDeletionRefusal}> $blockingVariations
     */
    public static function becauseVariationsBlockDeletion(string $productName, array $blockingVariations): self
    {
        $fragments = array_map(
            static fn (array $blocking): string => $blocking['reason'] === VariationDeletionRefusal::HAS_HISTORY
                ? "variation \"{$blocking['sku']}\" has {$blocking['count']} sale line(s)."
                : "variation \"{$blocking['sku']}\" has {$blocking['count']} in stock.",
            $blockingVariations,
        );

        return new self(
            "Product \"{$productName}\" cannot be deleted: ".implode(' ', $fragments),
            ProductDeletionRefusal::VARIATIONS_BLOCK_DELETION,
            $productName,
            $blockingVariations,
        );
    }
}
