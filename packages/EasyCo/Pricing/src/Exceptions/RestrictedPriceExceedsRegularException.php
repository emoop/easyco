<?php

namespace EasyCo\Pricing\Exceptions;

use RuntimeException;

/**
 * Thrown by RestrictedPriceWriteGuard::assertPriceIsSane() when a
 * PriceListItem being written into a "restricted" FIXED_ITEMS list (any
 * FIXED_ITEMS list that is not "Regular Prices" itself — see
 * pricing-persistence-domain-design.md §4.8) would price the target
 * strictly higher than the current "Regular Prices" price for that same
 * target/quantity-tier. Equality is allowed — this only fires on a
 * genuine excess, never on a match.
 */
final class RestrictedPriceExceedsRegularException extends RuntimeException
{
    public static function forTarget(string $targetId, string $newPriceFormatted, string $regularPriceFormatted): self
    {
        return new self(
            "Cannot save a price of {$newPriceFormatted} for target \"{$targetId}\": ".
            "it exceeds the current regular price of {$regularPriceFormatted}."
        );
    }
}
