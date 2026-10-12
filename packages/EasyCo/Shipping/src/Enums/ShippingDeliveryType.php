<?php

namespace EasyCo\Shipping\Enums;

/**
 * How a method delivers (shipping stage 5f): to the customer's address, to a courier office, to a parcel locker, or
 * something else. A DISPLAY and grouping fact only — it never changes a price, a rule or whether a pickup point is
 * required. A method without one (NULL) is simply not typed.
 *
 * It is ALSO the vocabulary of a destination's KIND (stage 5g): address, office, locker (destinationKinds()); OTHER is never one.
 */
enum ShippingDeliveryType: string
{
    case ADDRESS = 'address';
    case OFFICE = 'office';
    case LOCKER = 'locker';
    case OTHER = 'other';

    /**
     * The three values that name WHERE an order goes (shipping stage 5g, design 9.3.3): address, office, locker. OTHER is a method
     * LABEL only and is never a destination kind.
     *
     * @return list<self>
     */
    public static function destinationKinds(): array
    {
        return [self::ADDRESS, self::OFFICE, self::LOCKER];
    }

    /** The two kinds of a pickup point, in canonical order. @return list<self> */
    public static function pickupKinds(): array
    {
        return [self::OFFICE, self::LOCKER];
    }

    /** True for address, office and locker; false for OTHER. */
    public function isDestinationKind(): bool
    {
        return $this !== self::OTHER;
    }
}
