<?php

namespace EasyCo\Shipping;

/**
 * The one format shared by a ShippingClass code, a ShippingMethod carrier
 * code and the class-code keys of a method's rates: lowercase letters and
 * digits, with single "_" or "-" separators between groups, 1 to 64
 * characters. A value that does not match is rejected by the caller, never
 * normalized — a code is a stable machine-facing reference (a Variation will
 * point at it), so silently rewriting one would break that reference.
 */
final class ShippingCode
{
    public const MAX_LENGTH = 64;

    private const PATTERN = '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/D';

    public static function isValid(string $code): bool
    {
        return strlen($code) >= 1
            && strlen($code) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $code) === 1;
    }
}
