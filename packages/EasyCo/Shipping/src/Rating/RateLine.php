<?php

namespace EasyCo\Shipping\Rating;

use InvalidArgumentException;

/**
 * One cart line, as far as shipping cares: its shipping class code and its
 * quantity.
 *
 * shippingClass is Variation.shippingClass as it stands today — free text — so
 * it is NOT validated against the class list here: a code Shipping does not
 * know simply has no rate, and the method's fallback applies. A null or blank
 * value means "no class". A non-blank value is compared exactly as given
 * (never trimmed or case-folded: a class code is a stable machine reference,
 * see ShippingCode), so "Heavy" does not find a rate stored for "heavy".
 *
 * quantity is carried because a line has one, but NO rule multiplies by it:
 * shipping is charged per order, not per unit (§3.1).
 */
final class RateLine
{
    public readonly ?string $shippingClass;

    public function __construct(?string $shippingClass, public readonly int $quantity)
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("RateLine quantity must be at least 1, got {$quantity}.");
        }

        $this->shippingClass = $shippingClass === null || trim($shippingClass) === '' ? null : $shippingClass;
    }
}
