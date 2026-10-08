<?php

namespace EasyCo\Shipping\Enums;

/**
 * How a PER_CLASS method reads its class amounts — shipping-domain-design.md §12.2 (owner decision).
 *
 * REPLACE (the default, and every method that existed before this column): the most expensive class in the cart
 * REPLACES the method's base price (§3.1); class amounts are non-negative.
 * ADJUST: the base price stays, and every DISTINCT class present in the cart adds its SIGNED amount once whatever
 * its quantity (a surcharge is positive, a discount negative); the sum is floored at 0. A line with no class, or a
 * class with no amount on this method, contributes nothing.
 * Any other kind (FLAT, FREE, CARRIER) has no class amounts and so no mode: it is REPLACE.
 */
enum ShippingClassMode: string
{
    case REPLACE = 'replace';
    case ADJUST = 'adjust';
}
