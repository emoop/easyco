<?php

namespace EasyCo\Catalog\Exceptions;

use EasyCo\Catalog\Enums\VariationDeletionRefusal;
use RuntimeException;

/**
 * Thrown when a STANDARD variation cannot be hard-deleted — the refusal
 * half of catalog-domain-design.md §3.19 (G-D2, and §3.19.8 D's own
 * merchant-facing wording). Two reasons exist, and only two:
 *
 *  - it has HISTORY: at least one `operational_sales_sale_lines` row
 *    references it by `priceable_id` (any type — sale, refund,
 *    reservation, settlement; soft-deleted lines included). History is
 *    never deleted and never orphaned, so the variation stays;
 *  - its stock is not exactly zero.
 *
 * A REASON, NOT JUST A SENTENCE: the merchant-facing text is localised by
 * the application layer from `reason` + `variationSku` + `count`
 * (App\Services\VariationDeletionRefusalMessage, rendering
 * `products.deletion.refusal.{reason}`), so a Bulgarian merchant reads
 * Bulgarian. The exception's OWN message stays English on purpose: it is
 * what logs, exception dumps and support tickets carry, and a stack trace
 * whose wording depends on the request locale is a worse artefact.
 *
 * `count` is the sale-line count for HAS_HISTORY and the quantity on hand
 * for HAS_STOCK — one field, because both refusals are "the number that
 * must be zero first" and each reason names its own meaning in the
 * translated string.
 *
 * There is deliberately NO case for "it is a UNIVERSAL variation": that
 * refusal comes from Product::removeStandardVariation()'s own
 * \LogicException, because it is a structural rule the aggregate can
 * enforce by itself, not a cross-domain check, and it is not something a
 * merchant should ever be offered an archive for.
 */
final class VariationNotDeletableException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly VariationDeletionRefusal $reason,
        public readonly string $variationSku,
        public readonly int $count,
    ) {
        parent::__construct($message);
    }

    public static function becauseItHasHistory(string $variationSku, int $saleLineCount): self
    {
        return new self(
            "Variation \"{$variationSku}\" has {$saleLineCount} sale line(s) and cannot be deleted. ".
            'Archive it instead.',
            VariationDeletionRefusal::HAS_HISTORY,
            $variationSku,
            $saleLineCount,
        );
    }

    public static function becauseStockIsNotZero(string $variationSku, int $quantity): self
    {
        return new self(
            "Variation \"{$variationSku}\" still has {$quantity} in stock. Set stock to 0, or archive it instead.",
            VariationDeletionRefusal::HAS_STOCK,
            $variationSku,
            $quantity,
        );
    }
}
