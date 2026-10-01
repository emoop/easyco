<?php

namespace EasyCo\Shipping\Enums;

/**
 * What a shipping method's price is made of — shipping-domain-design.md §5.
 *
 * FLAT: one price for the zone. FREE: zero, kept as its own kind so the
 * configuration shows intent. PER_CLASS: the price depends on the shipping
 * classes in the cart (§3.1's most-expensive rule — stage 3). CARRIER: the
 * price is asked for live through a provider (§6) and is not configured here.
 */
enum ShippingMethodKind: string
{
    case FLAT = 'flat';
    case FREE = 'free';
    case PER_CLASS = 'per_class';
    case CARRIER = 'carrier';
}
