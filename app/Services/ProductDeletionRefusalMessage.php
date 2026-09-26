<?php

namespace App\Services;

use EasyCo\Catalog\Enums\ProductDeletionRefusal;
use EasyCo\Catalog\Exceptions\ProductNotDeletableException;

/**
 * Turns a product-level refusal into the sentence the MERCHANT reads, in
 * the merchant's own locale — the ONE place
 * `products.deletion.product_refusal.*` is resolved, exactly as
 * VariationDeletionRefusalMessage is for a variation (the confirmation
 * modal and every refusal notification go through it, so they cannot word
 * the same refusal differently, or disagree about the locale).
 *
 * WHY NOT ON THE EXCEPTION ITSELF: the exception lives in
 * `EasyCo\Catalog`, a domain package that must not know about app-layer
 * translation keys (its own docblock states this). The domain carries the
 * facts — reason, product name, and per blocking variation its SKU, count
 * and own VariationDeletionRefusal — and this is where they become UI text.
 *
 * THE PER-VARIATION FRAGMENTS ARE RENDERED FROM THE VARIATION'S OWN
 * `has_history`/`has_stock` KEYS rather than re-worded here: that is the
 * same sentence the variation-delete modal shows, and one refusal must not
 * grow two vocabularies. The fragments are full clauses, so joining them
 * with a space yields one grammatical sentence per locale — which is also
 * why the count of blocking variations needs no separate plural handling.
 */
final class ProductDeletionRefusalMessage
{
    public static function for(ProductNotDeletableException $refusal): string
    {
        if ($refusal->reason === ProductDeletionRefusal::NOT_ARCHIVED) {
            return __("products.deletion.product_refusal.{$refusal->reason->value}", [
                'product' => $refusal->productName,
            ]);
        }

        return __("products.deletion.product_refusal.{$refusal->reason->value}", [
            'product' => $refusal->productName,
            'variations' => implode(' ', array_map(
                static fn (array $blocking): string => __("products.deletion.blocked_variation.{$blocking['reason']->value}", [
                    'sku' => $blocking['sku'],
                    'count' => $blocking['count'],
                ]),
                $refusal->blockingVariations,
            )),
        ]);
    }
}
