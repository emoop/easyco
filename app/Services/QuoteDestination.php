<?php

namespace App\Services;

use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Rating\RateDestination;
use InvalidArgumentException;

/**
 * Where a quote is for, as the shipping zone matcher and the carriers need it
 * (shipping-domain-design.md §4, §6): the delivery type, the ISO country, the
 * settlement (a street address's city, or a pickup point's settlement) and an
 * optional postcode (ignored for a pickup point, as ZoneMatcher does). Plain
 * values, no validation beyond what ZoneDestination does later: the caller is
 * the HTTP layer, which validates.
 *
 * $pickupKind (shipping stage 5g-2a, design 9.3.6): for a PICKUP POINT, whether it is an office or a locker, or null when it
 * is not stated; never set for a street address, and only OFFICE or LOCKER. It prices the order (the free-shipping set is per
 * kind) and is bound into the pricing hash, so a handle quoted for one kind never verifies for another.
 */
final class QuoteDestination
{
    public function __construct(
        public readonly AddressDeliveryType $deliveryType,
        public readonly string $countryCode,
        public readonly ?string $settlement,
        public readonly ?string $postcode = null,
        public readonly ?ShippingDeliveryType $pickupKind = null,
    ) {
        if ($pickupKind !== null) {
            if ($deliveryType !== AddressDeliveryType::PICKUP_POINT) {
                throw new InvalidArgumentException('A street address has no pickup kind.');
            }

            if (! in_array($pickupKind, ShippingDeliveryType::pickupKinds(), true)) {
                throw new InvalidArgumentException('A pickup kind is office or locker.');
            }
        }
    }

    /**
     * THE ONE BUILDER of a destination from address facts (stage 4e): the quote endpoint's typed fields, a saved address
     * and the checkout's address all come through here, so a quote and the checkout that follows it can never read the
     * same address two ways. $settlement is a street address's CITY or a pickup point's settlement; the postcode is
     * dropped for a pickup point (ZoneMatcher and the pricing hash ignore it there anyway). $pickupKind is the pickup
     * point's kind (5g-2a), null when not stated.
     */
    public static function forAddress(AddressDeliveryType $deliveryType, string $countryCode, ?string $settlement, ?string $postcode = null, ?ShippingDeliveryType $pickupKind = null): self
    {
        return new self(
            $deliveryType,
            $countryCode,
            $settlement === null ? null : trim($settlement),
            $deliveryType === AddressDeliveryType::PICKUP_POINT ? null : $postcode,
            $pickupKind,
        );
    }

    /** A saved or resolved Address as a destination: a pickup point's settlement and stored kind, a street address's city and postal code. */
    public static function fromAddress(\EasyCo\Address\Address $address): self
    {
        $pickup = $address->deliveryType() === AddressDeliveryType::PICKUP_POINT;
        $kind = $pickup && $address->pickupPointKind() !== null ? ShippingDeliveryType::tryFrom($address->pickupPointKind()) : null;

        return self::forAddress($address->deliveryType(), (string) $address->country(), $pickup ? $address->settlement() : $address->city(), $address->postalCode(), $kind);
    }

    public function isPickupPoint(): bool
    {
        return $this->deliveryType === AddressDeliveryType::PICKUP_POINT;
    }

    /** The destination's kind: ADDRESS for a street address; OFFICE, LOCKER or null (not stated) for a pickup point. */
    public function kind(): ?ShippingDeliveryType
    {
        return $this->isPickupPoint() ? $this->pickupKind : ShippingDeliveryType::ADDRESS;
    }

    /**
     * The kinds this destination stands for, in canonical order (stage 5g-2b): an address is [address]; an office or a locker is
     * itself; a pickup point of unstated kind is [office, locker]. The kind-aware free-shipping hint reads it as its SCOPE.
     *
     * @return list<ShippingDeliveryType>
     */
    public function scopeKinds(): array
    {
        if (! $this->isPickupPoint()) {
            return [ShippingDeliveryType::ADDRESS];
        }

        return $this->pickupKind === null ? ShippingDeliveryType::pickupKinds() : [$this->pickupKind];
    }

    /** The same destination as the rate calculator reads it (the free-shipping set is per kind). */
    public function rateDestination(): RateDestination
    {
        return $this->isPickupPoint() ? RateDestination::pickup($this->pickupKind) : RateDestination::address();
    }

    /** What the pricing hash binds: 'address' | 'office' | 'locker' | 'pickup' ('pickup' = a pickup point of unstated kind). */
    public function hashKind(): string
    {
        return $this->kind()?->value ?? 'pickup';
    }
}
