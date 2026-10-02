<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\PickupPoint;

/**
 * The offices and lockers of a carrier (shipping-domain-design.md §6). Bound in
 * the container as `shipping.carrier.<code>.pickup`. It only LISTS what Address
 * already knows how to store (carrierCode, pickupPointReference, settlement).
 *
 * Same obligations as ShippingRateProvider: side-effect free, HONOUR $budget as
 * the timeout of your own HTTP client, no database transaction held open, throw
 * on failure (CarrierCallGuard turns it into "unavailable"), no customer data in
 * an exception message. Every returned point must carry the requested country.
 */
interface PickupPointProvider
{
    /** @return list<PickupPoint> offices and lockers for a settlement; empty means none */
    public function pickupPointsIn(string $countryCode, string $settlement, CallBudget $budget): array;
}
