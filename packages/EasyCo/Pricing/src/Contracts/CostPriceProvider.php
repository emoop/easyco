<?php

namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\Money;

/**
 * The public-facing cost-lookup contract — the only ProductCost-related
 * contract any other package (eventually Checkout, per checkout-domain-
 * design.md §9.3) may depend on.
 *
 * NULLABLE RETURN — THE ONE REAL AMENDMENT TO pricing-domain-design.md
 * §4.2's ORIGINAL SKETCH (which had a non-nullable Money return).
 * checkout-domain-design.md §9.1 recorded why: "no row = no cost
 * recorded" needs a real, representable "unknown," and null is that —
 * not zero, not an exception. Amending it here since nothing ever
 * consumed the old signature (it was never implemented).
 *
 * Never expose this through a storefront/public API route — cost data
 * leaking to a customer's browser is a business-risk bug, not just a
 * technical one (pricing-domain-design.md §2.4).
 */
interface CostPriceProvider
{
    /** null = no cost recorded for this (priceableId, currency) pair. */
    public function costFor(string $priceableId, string $currency): ?Money;
}
