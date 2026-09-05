<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use EasyCo\Pricing\Contracts\CostPriceProvider;
use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\Money;

/**
 * A thin wrapper over ProductCostRepository, NOT a duplicate persistence
 * path — this class owns no query logic of its own beyond delegating to
 * the repository and unwrapping the result.
 */
final class EloquentCostPriceProvider implements CostPriceProvider
{
    public function __construct(
        private readonly ProductCostRepository $productCosts,
    ) {
    }

    public function costFor(string $priceableId, string $currency): ?Money
    {
        return $this->productCosts->findByPriceableIdAndCurrency($priceableId, $currency)?->cost();
    }
}
