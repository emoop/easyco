<?php

namespace EasyCo\Shipping\Exceptions;

use InvalidArgumentException;

/** A carrier could not be registered in the CarrierRegistry (shipping-domain-design.md §6). */
final class CarrierRegistrationException extends InvalidArgumentException
{
    public static function invalidCode(string $code): self
    {
        return new self("Carrier code \"{$code}\" is invalid: use lowercase letters and digits with single \"_\" or \"-\" separators, 1 to 64 characters.");
    }

    public static function alreadyRegistered(string $code): self
    {
        return new self("Carrier \"{$code}\" is already registered; a second registration would silently replace the first.");
    }

    public static function emptyName(string $code): self
    {
        return new self("Carrier \"{$code}\" must be registered with a non-empty display name.");
    }

    public static function noCapabilities(string $code): self
    {
        return new self("Carrier \"{$code}\" must be registered with at least one capability (rate, pickup or label).");
    }
}
