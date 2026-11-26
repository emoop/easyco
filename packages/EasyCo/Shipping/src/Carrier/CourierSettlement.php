<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;
use EasyCo\Shipping\ShippingCode;

/**
 * A settlement as a courier lists it (Speedy `location/site`, Econt `getCities`), for the settlement mapping of
 * courier-integration-design.md §3.3. `reference` is the courier's own id (a Speedy site id, an Econt city id), compared exactly.
 * The postcode and the region NAME are what the courier says, unchecked: the mapping decides what to make of them.
 */
final class CourierSettlement
{
    public function __construct(
        public readonly string $carrierCode,
        public readonly string $reference,
        public readonly string $name,
        public readonly ?string $postcode = null,
        public readonly ?string $regionName = null,
    ) {
        $class = self::class;

        if (! ShippingCode::isValid($carrierCode)) {
            throw InvalidCarrierDataException::field($class, 'carrierCode', 'must be a valid carrier code');
        }

        CarrierValue::nonEmpty($class, 'reference', $reference, 32);
        CarrierValue::nonEmpty($class, 'name', $name, 120);
    }
}
