<?php

namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\ProductCost;

/**
 * INTERNAL to this package — mirrors pricing-domain-design.md §4.2's own
 * language: "PriceList, PriceListItem, ... and their repositories are
 * internal; no other domain package may use them directly." The same
 * applies to ProductCost/ProductCostRepository. Contracts\
 * CostPriceProvider is the only contract any other package (eventually
 * Checkout) may depend on.
 */
interface ProductCostRepository
{
    public function save(ProductCost $cost): void;

    public function findByPriceableIdAndCurrency(string $priceableId, string $currency): ?ProductCost;
}
