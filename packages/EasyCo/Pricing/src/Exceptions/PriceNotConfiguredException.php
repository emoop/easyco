<?php

namespace EasyCo\Pricing\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentPriceResolver::resolve() when the reserved
 * "Regular Prices" system PriceList exists (i.e. the pricing system
 * itself is correctly set up) but carries no price for the requested
 * priceableId — a normal, expected business state (a merchant created
 * a product and hasn't priced it yet), not a system-misconfiguration
 * failure.
 *
 * Deliberately a distinct, catchable type from the untyped
 * RuntimeException resolve() still throws when the "Regular Prices"
 * list itself hasn't been seeded at all — that failure means the
 * whole pricing system isn't set up and must keep failing loudly;
 * nothing should ever catch it and turn it into a clean HTTP error.
 * This exception exists specifically so an external caller (Cart,
 * presumably Checkout later) can catch the no-price-yet case without
 * also silently swallowing that other, much more serious one — see
 * cart-domain-design.md §12/§CartController.
 */
final class PriceNotConfiguredException extends RuntimeException
{
    public static function forPriceableId(string $priceableId): self
    {
        return new self(
            "No \"Regular Prices\" price is set for priceableId \"{$priceableId}\"."
        );
    }
}
