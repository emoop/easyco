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
}
