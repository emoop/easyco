<?php

namespace EasyCo\Shipping\Enums;

/**
 * Which KIND of destination a shipping method serves (shipping stage 6a, shipping-domain-design.md section 9.2): a customer's
 * street address, a pickup point (office or locker), or either. The merchant's rule, and the one thing eligibility reads
 * (ShippingMethod::servesPickupPoint()); the delivery-type LABEL stays a display and grouping fact, kept coherent with this by
 * the label x scope rule in ShippingMethod.
 */
enum ShippingDestinationScope: string
{
    case ADDRESS = 'address';
    case PICKUP = 'pickup';
    case ANY = 'any';

    /**
     * THE ONE RULE of which kind of destination a scope serves (stage 6b): ADDRESS only a street address, PICKUP only a pickup
     * point, ANY both. ShippingMethod::servesPickupPoint() and the quote's MethodQuote both call this, so the matrix exists once.
     */
    public function serves(bool $isPickupPoint): bool
    {
        return match ($this) {
            self::ADDRESS => ! $isPickupPoint,
            self::PICKUP => $isPickupPoint,
            self::ANY => true,
        };
    }
}
