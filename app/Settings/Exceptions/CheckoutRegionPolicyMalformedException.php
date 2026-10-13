<?php

namespace App\Settings\Exceptions;

use RuntimeException;

/**
 * A stored checkout region setting (`checkout.region_default`, `checkout.region_countries`) is not what its owner,
 * CheckoutRegionPolicy, would ever have written: thrown, never guessed around (CLAUDE.md rule 8). The admin page validates before it
 * saves, so this only happens after a hand edit of the settings table. The message names the key and the reason, never the whole value.
 */
final class CheckoutRegionPolicyMalformedException extends RuntimeException
{
    public static function because(string $key, string $reason): self
    {
        return new self("The stored setting \"{$key}\" is malformed: {$reason}. Open Settings > Checkout and save it again.");
    }
}
