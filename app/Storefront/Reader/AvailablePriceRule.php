<?php

namespace App\Storefront\Reader;

use EasyCo\Pricing\PriceQuote;

/**
 * P6a: THE ONE rule of «which variations a product's «от» price is taken from». It is used by the card, the product page (CatalogReader::productPrice()) AND the stored `price_from_minor` that listings are sorted by
 * (ProductSortPriceRefresher), so the three can never differ.
 *
 *  - the AVAILABLE variations: priced (a quote exists) and in stock; the caller passes only the SHOWN variations, so a variation that is not active / not shown is never here;
 *  - when none is available (the product is sold out), ALL the priced variations: a sold-out product still has a price;
 *  - when nothing is priced: nothing (the product has no price: null on the card, NULL in the column).
 *
 * Example: sizes at 10 (sold out), 12, 12, 14, 16 → the quotes of 12, 12, 14 and 16: «От 12.00».
 */
final class AvailablePriceRule
{
    /**
     * @param list<string> $variationIds the product's SHOWN variations
     * @param array<string, PriceQuote> $quotes quotes by variation id (any superset; a variation with no price is absent)
     * @param array<string, int> $stock quantity by variation id (a missing id is 0)
     * @return array<string, PriceQuote> the quotes the product's price is taken from (empty = no price)
     */
    public static function select(array $variationIds, array $quotes, array $stock): array
    {
        $priced = [];
        $available = [];

        foreach ($variationIds as $variationId) {
            if (! isset($quotes[$variationId])) {
                continue;
            }

            $priced[$variationId] = $quotes[$variationId];

            if (($stock[$variationId] ?? 0) > 0) {
                $available[$variationId] = $quotes[$variationId];
            }
        }

        return $available !== [] ? $available : $priced;
    }
}
