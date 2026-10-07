<?php

namespace EasyCo\Shipping\Rating;

use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;

/**
 * Prices the shipping methods of the zone an order matched (stage 3b). Pure: it
 * reads the methods and the request it is given, nothing else.
 *
 * THE RULES — per ORDER, never per unit (shipping-domain-design.md §3.1, §5, §5.1):
 *
 *  - FREE: 0, whatever the cart.
 *  - FLAT: the method's amountMinor.
 *  - PER_CLASS: each line takes the rate of its shipping class on this method; a
 *    line with no class, or whose class has no rate here (an unknown code
 *    included — Variation.shippingClass is still free text), takes the method's
 *    amountMinor, the fallback. The order is charged the SINGLE HIGHEST of
 *    those per-line rates — the most-expensive-class rule — NEVER their sum, and
 *    quantities do not multiply it. A rate of 0 is valid (all lines at 0 charge 0).
 *  - FREE-SHIPPING THRESHOLD (FLAT and PER_CLASS): when freeAboveMinor is set and
 *    goodsAfterDiscountMinor >= freeAboveMinor the charge is 0. It is ">=": an
 *    order exactly at the threshold IS free. The basis is the order's
 *    subtotal - discount, what the customer actually pays for goods (§5.1).
 *  - CARRIER: "needs a carrier quote". It is never priced locally, and how
 *    freeAboveMinor combines with a live quote is still open (queue note), so it
 *    is deliberately NOT applied here.
 *
 * ratesFor() returns the ACTIVE methods only, ordered by sortOrder ascending
 * then id ascending (numerically, so 2 comes before 10). It trusts the caller to
 * pass the matched zone's methods; it does not know zones.
 */
final class ShippingRateCalculator
{
    /**
     * @param  iterable<ShippingMethod>  $methods  in any order
     * @return list<MethodRate>
     */
    public function ratesFor(iterable $methods, RateRequest $request): array
    {
        $active = [];

        foreach ($methods as $method) {
            if ($method->isActive()) {
                $active[] = $method;
            }
        }

        usort($active, self::byPrecedence(...));

        return array_map(fn (ShippingMethod $method): MethodRate => $this->rateFor($method, $request), $active);
    }

    /** One method, active or not: the caller decides which methods to ask about. */
    public function rateFor(ShippingMethod $method, RateRequest $request): MethodRate
    {
        $id = (string) $method->id();

        return match ($method->kind()) {
            ShippingMethodKind::CARRIER => MethodRate::needsCarrierQuote($id, $request->currency, (string) $method->carrierCode()),
            ShippingMethodKind::FREE => MethodRate::priced($id, $request->currency, 0),
            ShippingMethodKind::FLAT => $this->pricedLocally($id, $method, $request, (int) $method->amountMinor()),
            ShippingMethodKind::PER_CLASS => $this->pricedLocally($id, $method, $request, $this->perClassCharge($method, $request)),
        };
    }

    /**
     * A FLAT or PER_CLASS method: the threshold-adjusted charge, plus the free-
     * shipping facts that go with it (stage 3e, §5.1). The charge itself is
     * byte-for-byte what applyThreshold() has always returned; the two extra
     * fields only REPORT the threshold — they never price anything.
     */
    private function pricedLocally(string $id, ShippingMethod $method, RateRequest $request, int $charge): MethodRate
    {
        $threshold = $method->freeAboveMinor();
        $amount = $this->applyThreshold($method, $request, $charge);

        if ($threshold === null) {
            return MethodRate::priced($id, $request->currency, $amount);
        }

        // ">=": at or above the threshold the goods are free and nothing remains.
        $remaining = $request->goodsAfterDiscountMinor >= $threshold ? 0 : $threshold - $request->goodsAfterDiscountMinor;

        return MethodRate::priced($id, $request->currency, $amount, $threshold, $remaining);
    }

    private function perClassCharge(ShippingMethod $method, RateRequest $request): int
    {
        $fallback = (int) $method->amountMinor();
        $rates = $method->classRates();
        $highest = null;

        foreach ($request->lines as $line) {
            $rate = $line->shippingClass !== null && array_key_exists($line->shippingClass, $rates)
                ? $rates[$line->shippingClass]
                : $fallback;

            $highest = $highest === null ? $rate : max($highest, $rate);
        }

        // RateRequest refuses an empty list, so at least one line set it.
        return (int) $highest;
    }

    private function applyThreshold(ShippingMethod $method, RateRequest $request, int $charge): int
    {
        $threshold = $method->freeAboveMinor();

        return $threshold !== null && $request->goodsAfterDiscountMinor >= $threshold ? 0 : $charge;
    }

    /** sortOrder ascending, then id ascending (numerically when both are numbers). */
    private static function byPrecedence(ShippingMethod $a, ShippingMethod $b): int
    {
        if ($a->sortOrder() !== $b->sortOrder()) {
            return $a->sortOrder() <=> $b->sortOrder();
        }

        $idA = $a->id();
        $idB = $b->id();

        if ($idA === $idB) {
            return 0;
        }

        if ($idA === null || $idB === null) {
            return $idA === null ? 1 : -1;
        }

        return ctype_digit($idA) && ctype_digit($idB) ? (int) $idA <=> (int) $idB : strcmp($idA, $idB);
    }
}
