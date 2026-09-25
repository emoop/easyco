<?php

namespace App\Services;

use EasyCo\Pricing\Contracts\CostPriceProvider;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;

/**
 * Resolves the live price for one checkout line, per checkout-domain-
 * design.md §8.3 step 3 / §9.3.
 *
 * priceableId === variationId directly — no separate Variation lookup
 * needed here, per Variation's own documented identity
 * ("priceableId() == its own id"). CatalogScopeResolver still needs the
 * Variation internally for scope matching, hence that dependency.
 *
 * NO PROFIT COMPUTED HERE ANY MORE — operational-sales-domain-design.md
 * §3.13 stage 4a's own review removed it: this class used to compute a
 * pre-promotion profit (amount - unitCost x quantity, itself scaled by
 * quantity to fix a real bug — see this task's own commit history), but
 * that figure became dead the moment SaleLineSnapshotBuilder started
 * computing the real, post-promotion profit as the ONE place it's
 * computed for a checkout SALE line. This class now only resolves the
 * raw unitCost (null = genuinely unknown, §9.3) and hands it through.
 *
 * $scope/$quote ALSO CARRY productId/matchingScopeReferenceIds/
 * isDiscounted THROUGH TO THE RESULT — not used by this class's own
 * price/profit math, but the Promotions path (PromotionValidator/
 * PromotionDiscountCalculator) needs exactly these per line, same
 * "one array, extended rather than recomputed twice" pattern
 * CartController::serializeCart() already established. Without this,
 * a Checkout orchestrator would have to call
 * CatalogScopeResolver::forVariation() a second time per line.
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

        $regularUnitPrice = $quote->regular->gross();
        $unitPrice = $quote->final->gross();
        $amount = $unitPrice->multiply($quantity);

        // Raw value only — null means genuinely unknown (§9.3). Profit is
        // no longer computed here at all: SaleLineSnapshotBuilder is now
        // the ONE place that computes it, on net, after the promotion
        // share (operational-sales-domain-design.md §3.13 stage 4a).
        $unitCost = $this->costPriceProvider->costFor($variationId, $currency);

        return CheckoutLinePricingResult::create(
            variationId: $variationId,
            quantity: $quantity,
            productId: $scope['productId'],
            matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
            isDiscounted: $quote->isDiscounted(),
            regularUnitPrice: $regularUnitPrice,
            unitPrice: $unitPrice,
            amount: $amount,
            unitCost: $unitCost,
            productName: $scope['productName'],
            sku: $scope['sku'],
        );
    }
}
