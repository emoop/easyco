<?php

namespace EasyCo\Shipping\Rating;

use EasyCo\Shipping\Enums\ShippingDeliveryType;
use InvalidArgumentException;

/**
 * Where an order goes, as the rate calculator needs it (shipping stage 5g, shipping-domain-design.md 9.3.3): a street address
 * (kind ADDRESS), or a pickup point whose kind is office, locker, or NOT STATED (null = "a pickup point, kind unspecified").
 * Plain values only: no Cart, Address or Quote type.
 *
 * It exists for ONE rule, the free-shipping threshold: the threshold applies only where the method's free set covers the
 * destination, and an unstated kind is never priced cheaper than a stated one (ShippingMethod::freeAppliesTo()).
 * ShippingDeliveryType::OTHER is a method label and is refused as a kind.
 */
final class RateDestination
{
    public function __construct(
        public readonly bool $isPickupPoint,
        public readonly ?ShippingDeliveryType $kind,
    ) {
        if ($kind === ShippingDeliveryType::OTHER) {
            throw new InvalidArgumentException('RateDestination kind must be address, office or locker; "other" is a method label, not a destination.');
        }

        if ($isPickupPoint && $kind === ShippingDeliveryType::ADDRESS) {
            throw new InvalidArgumentException('A pickup point cannot have the kind "address".');
        }

        if (! $isPickupPoint && $kind !== ShippingDeliveryType::ADDRESS) {
            throw new InvalidArgumentException('A street address has the kind "address" and no other.');
        }
    }

    public static function address(): self
    {
        return new self(false, ShippingDeliveryType::ADDRESS);
    }

    /** @param ShippingDeliveryType|null $kind office, locker, or null = the kind is not stated */
    public static function pickup(?ShippingDeliveryType $kind = null): self
    {
        return new self(true, $kind);
    }
}
