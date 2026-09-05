<?php

namespace App\Services;

use EasyCo\Pricing\Contracts\CostPriceProvider;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;

/**
 * Resolves the live price and profit for one checkout line, per
 * checkout-domain-design.md §8.3 step 3 / §9.3.
 *
 * priceableId === variationId directly — no separate Variation lookup
 * needed here, per Variation's own documented identity
 * ("priceableId() == its own id"). CatalogScopeResolver still needs the
 * Variation internally for scope matching, hence that dependency.
 *
 * COST IS SCALED BY QUANTITY — a correction to checkout-domain-
 * design.md §9.3's original formula, which omitted this (see this
 * task's own commit message for the full explanation). amount is
 * already unitPrice × quantity, so the cost side of the subtraction
 * must be too, or profit would be wrong for any quantity > 1.
 */
class CheckoutLinePricer
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
        private readonly CatalogScopeResolver $catalogScopeResolver,
        private readonly CostPriceProvider $costPriceProvider,
    ) {
    }

    /**
     * @throws PriceNotConfiguredException Same exception Cart's own
     *   add-time resolution can throw; the caller (future Checkout
     *   orchestration) lets this abort the whole transaction cleanly,
     *   per §8.3 step 3 — nothing partial is ever committed.
     */
    public function priceLine(string $variationId, int $quantity, string $currency): CheckoutLinePricingResult
    {
        $scope = $this->catalogScopeResolver->forVariation($variationId);

        $quote = $this->priceResolver->resolve(new PriceContext(
            priceableId: $variationId,
            quantity: $quantity,
            currency: $currency,
            productId: $scope['productId'],
            matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
        ));

        $unitPrice = $quote->final->gross();
        $amount = $unitPrice->multiply($quantity);

        $unitCost = $this->costPriceProvider->costFor($variationId, $currency);
        $costRecorded = $unitCost !== null;
        $totalCost = ($unitCost ?? Money::zero($currency))->multiply($quantity);
        $profit = $amount->subtract($totalCost);

        return CheckoutLinePricingResult::create(
            variationId: $variationId,
            quantity: $quantity,
            unitPrice: $unitPrice,
            amount: $amount,
            profit: $profit,
            costRecorded: $costRecorded,
        );
    }
}
