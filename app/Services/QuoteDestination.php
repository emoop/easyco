<?php

namespace App\Services;

use EasyCo\Address\Enums\AddressDeliveryType;

/**
 * Where a quote is for, as the shipping zone matcher and the carriers need it
 * (shipping-domain-design.md §4, §6): the delivery type, the ISO country, the
 * settlement (a street address's city, or a pickup point's settlement) and an
 * optional postcode (ignored for a pickup point, as ZoneMatcher does). Plain
 * values, no validation beyond what ZoneDestination does later: the caller is
 * the HTTP layer, which validates.
 */
final class QuoteDestination
{
    public function __construct(
        public readonly AddressDeliveryType $deliveryType,
        public readonly string $countryCode,
        public readonly ?string $settlement,
        public readonly ?string $postcode = null,
    ) {
    }

    public function isPickupPoint(): bool
    {
        return $this->deliveryType === AddressDeliveryType::PICKUP_POINT;
    }
}
