<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\ShippingCode;
use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/**
 * An office or locker a carrier lists (shipping-domain-design.md §6).
 *
 * IT CARRIES ITS OWN COUNTRY (decision D1: the delivery country is a validated
 * ISO code on every address, pickup points included), besides the carrier's code
 * and the carrier's own reference for it. Those are the three fields Address
 * stores for a PICKUP_POINT (carrierCode, pickupPointReference, settlement) plus
 * the country, so choosing a point needs no translation.
 *
 * `reference` is opaque and compared exactly; it is never trimmed or case-folded.
 */
final class PickupPoint
{
    public function __construct(
        public readonly string $carrierCode,
        public readonly string $reference,
        public readonly string $name,
        public readonly string $countryCode,
        public readonly string $settlement,
        public readonly string $addressLine,
    ) {
        $class = self::class;

        if (! ShippingCode::isValid($carrierCode)) {
            throw InvalidCarrierDataException::field($class, 'carrierCode', 'must be a valid carrier code');
        }

        CarrierValue::nonEmpty($class, 'reference', $reference, 128);
        CarrierValue::nonEmpty($class, 'name', $name);
        CarrierValue::country($class, 'countryCode', $countryCode);
        CarrierValue::nonEmpty($class, 'settlement', $settlement);
        CarrierValue::nonEmpty($class, 'addressLine', $addressLine);
    }
}
