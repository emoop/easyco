<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;
use EasyCo\Shipping\ShippingCode;

/**
 * An office or locker a carrier lists (shipping-domain-design.md §6; courier-integration-design.md §2.2, §3.1).
 *
 * IT CARRIES ITS OWN COUNTRY (decision D1: the delivery country is a validated
 * ISO code on every address, pickup points included), besides the carrier's code
 * and the carrier's own reference for it. Those are the three fields Address
 * stores for a PICKUP_POINT (carrierCode, pickupPointReference, settlement) plus
 * the country, so choosing a point needs no translation.
 *
 * `reference` is opaque and compared exactly; it is never trimmed or case-folded. It is the identifier the courier's
 * shipment API takes (courier-integration-design.md §3.2).
 *
 * COUR-1a additions: kind (office / locker), the courier's raw type, its public code and internal id where they differ
 * from the reference, postcode, coordinates, the courier's own settlement id (Speedy siteId, Econt city.id) and the
 * features the courier declares (card / cash COD, open-before-payment, maximum weight). All are optional except `kind`.
 */
final class PickupPoint
{
    /** @param array<string, scalar|null> $features */
    public function __construct(
        public readonly string $carrierCode,
        public readonly string $reference,
        public readonly string $name,
        public readonly string $countryCode,
        public readonly string $settlement,
        public readonly string $addressLine,
        public readonly PickupPointKind $kind,
        public readonly ?string $postcode = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $courierType = null,
        public readonly ?string $code = null,
        public readonly ?string $externalId = null,
        public readonly ?string $courierSettlementRef = null,
        public readonly array $features = [],
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

        if ($courierSettlementRef !== null) {
            // Digits only (1 to 18): the sync joins it with CAST(... AS UNSIGNED), which must never meet anything else.
            if (preg_match('/^\d{1,18}$/D', $courierSettlementRef) !== 1) {
                throw InvalidCarrierDataException::field($class, 'courierSettlementRef', 'must be 1 to 18 digits');
            }
        }

        if ($latitude !== null && ($latitude < -90.0 || $latitude > 90.0)) {
            throw InvalidCarrierDataException::field($class, 'latitude', 'must be between -90 and 90');
        }

        if ($longitude !== null && ($longitude < -180.0 || $longitude > 180.0)) {
            throw InvalidCarrierDataException::field($class, 'longitude', 'must be between -180 and 180');
        }

        if (($latitude === null) !== ($longitude === null)) {
            throw InvalidCarrierDataException::field($class, 'latitude/longitude', 'must be given together');
        }
    }
}
