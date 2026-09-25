<?php

namespace App\Services;

use EasyCo\Catalog\Exceptions\VariationNotDeletableException;

/**
 * Turns a refusal into the sentence the MERCHANT reads, in the merchant's
 * own locale — the ONE place `products.deletion.refusal.*` is resolved
 * (the confirmation modal's body and every refusal notification go through
 * it, so they cannot word the same refusal differently, or disagree about
 * the locale).
 *
 * WHY NOT ON THE EXCEPTION ITSELF: the exception lives in
 * `EasyCo\Catalog`, a domain package that must not know about app-layer
 * translation keys (its own docblock states this). The domain carries the
 * facts — reason, SKU, count — and this is where they become UI text.
 *
 * WHY THE KEY IS BUILT FROM THE ENUM VALUE: `reason` is the enum, its
 * value IS the key fragment (`has_history`, `has_stock`), so adding a
 * reason without a translation is a missing-key failure the UI test suite
 * catches rather than a silently English sentence.
 */
final class VariationDeletionRefusalMessage
{
    public static function for(VariationNotDeletableException $refusal): string
    {
        return __("products.deletion.refusal.{$refusal->reason->value}", [
            'sku' => $refusal->variationSku,
            'count' => $refusal->count,
        ]);
    }
}
