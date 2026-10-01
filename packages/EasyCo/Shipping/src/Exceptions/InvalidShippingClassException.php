<?php

namespace EasyCo\Shipping\Exceptions;

use InvalidArgumentException;

/** A ShippingClass invariant was violated at construction or update. */
final class InvalidShippingClassException extends InvalidArgumentException
{
    public static function invalidCode(string $code): self
    {
        return new self("ShippingClass code \"{$code}\" is invalid: use lowercase letters and digits with single \"_\" or \"-\" separators, 1 to 64 characters.");
    }

    public static function emptyName(): self
    {
        return new self('ShippingClass name must not be empty.');
    }

    public static function nameTooLong(int $max): self
    {
        return new self("ShippingClass name must not be longer than {$max} characters.");
    }
}
