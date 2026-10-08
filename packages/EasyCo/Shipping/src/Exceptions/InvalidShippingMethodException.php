<?php

namespace EasyCo\Shipping\Exceptions;

use EasyCo\Shipping\Enums\ShippingMethodKind;
use InvalidArgumentException;

/** A ShippingMethod invariant was violated at construction or update. */
final class InvalidShippingMethodException extends InvalidArgumentException
{
    public static function emptyName(): self
    {
        return new self('ShippingMethod name must not be empty.');
    }

    public static function nameTooLong(int $max): self
    {
        return new self("ShippingMethod name must not be longer than {$max} characters.");
    }

    public static function emptyZoneId(): self
    {
        return new self('ShippingMethod zoneId must not be empty.');
    }

    public static function negativeSortOrder(int $sortOrder): self
    {
        return new self("ShippingMethod sortOrder must not be negative, got {$sortOrder}.");
    }

    public static function negativeAmount(string $field, int $value): self
    {
        return new self("ShippingMethod {$field} must not be negative, got {$value}.");
    }

    public static function amountRequired(ShippingMethodKind $kind): self
    {
        return new self("A {$kind->name} ShippingMethod requires an amountMinor.");
    }

    public static function amountNotAllowed(ShippingMethodKind $kind): self
    {
        return new self("A {$kind->name} ShippingMethod must not have an amountMinor.");
    }

    public static function classRatesNotAllowed(ShippingMethodKind $kind): self
    {
        return new self("A {$kind->name} ShippingMethod must not have classRates; only PER_CLASS does.");
    }

    public static function invalidClassRate(string $classCode, mixed $amount): self
    {
        $shown = is_int($amount) ? (string) $amount : get_debug_type($amount);

        return new self("ShippingMethod class rate for \"{$classCode}\" must be a non-negative integer amount in minor units, got {$shown}.");
    }

    public static function invalidSignedClassRate(string $classCode, mixed $amount): self
    {
        $shown = is_scalar($amount) ? var_export($amount, true) : get_debug_type($amount);

        return new self("ShippingMethod class adjustment for \"{$classCode}\" must be an integer amount in minor units (negative for a discount), got {$shown}.");
    }

    public static function classModeNotAllowed(ShippingMethodKind $kind): self
    {
        return new self("A {$kind->name} ShippingMethod has no class amounts and so no class mode: only PER_CLASS can ADJUST.");
    }

    public static function invalidClassRateCode(mixed $classCode): self
    {
        $shown = is_string($classCode) ? $classCode : get_debug_type($classCode);

        return new self("ShippingMethod class rate key \"{$shown}\" is not a valid shipping class code.");
    }

    public static function carrierCodeRequired(): self
    {
        return new self('A CARRIER ShippingMethod requires a carrierCode.');
    }

    public static function carrierCodeNotAllowed(ShippingMethodKind $kind): self
    {
        return new self("A {$kind->name} ShippingMethod must not have a carrierCode; only CARRIER does.");
    }

    public static function invalidCarrierCode(string $carrierCode): self
    {
        return new self("ShippingMethod carrierCode \"{$carrierCode}\" is invalid: use lowercase letters and digits with single \"_\" or \"-\" separators, 1 to 64 characters.");
    }

    public static function freeAboveNotAllowed(ShippingMethodKind $kind): self
    {
        return new self("A {$kind->name} ShippingMethod must not have a freeAboveMinor threshold: it is already free, so the threshold is contradictory.");
    }

    public static function courierTooLong(int $max): self
    {
        return new self("ShippingMethod courier is longer than {$max} characters.");
    }

    public static function courierNotPlain(): self
    {
        return new self('ShippingMethod courier must be a single line of plain text: no control or direction-override characters.');
    }
}
