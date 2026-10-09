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

    /**
     * THE ONE BUILDER of a destination from address facts (stage 4e): the quote endpoint's typed fields, a saved address
     * and the checkout's address all come through here, so a quote and the checkout that follows it can never read the
     * same address two ways. $settlement is a street address's CITY or a pickup point's settlement; the postcode is
     * dropped for a pickup point (ZoneMatcher and the pricing hash ignore it there anyway).
     */
    public static function forAddress(AddressDeliveryType $deliveryType, string $countryCode, ?string $settlement, ?string $postcode = null): self
    {
        return new self(
            $deliveryType,
            $countryCode,
            $settlement === null ? null : trim($settlement),
            $deliveryType === AddressDeliveryType::PICKUP_POINT ? null : $postcode,
        );
    }

    /** A saved or resolved Address as a destination: a pickup point's settlement, a street address's city and postal code. */
    public static function fromAddress(\EasyCo\Address\Address $address): self
    {
        $pickup = $address->deliveryType() === AddressDeliveryType::PICKUP_POINT;

        return self::forAddress($address->deliveryType(), (string) $address->country(), $pickup ? $address->settlement() : $address->city(), $address->postalCode());
    }

    public function isPickupPoint(): bool
    {
        return $this->deliveryType === AddressDeliveryType::PICKUP_POINT;
    }
}
