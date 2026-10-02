<?php

namespace EasyCo\Shipping\Exceptions;

use InvalidArgumentException;

/** A ShippingZone invariant was violated at construction or update. */
final class InvalidShippingZoneException extends InvalidArgumentException
{
    public static function emptyName(): self
    {
        return new self('ShippingZone name must not be empty.');
    }

    public static function nameTooLong(int $max): self
    {
        return new self("ShippingZone name must not be longer than {$max} characters.");
    }

    public static function negativeSortOrder(int $sortOrder): self
    {
        return new self("ShippingZone sortOrder must not be negative, got {$sortOrder}.");
    }

    public static function noCountryCodes(): self
    {
        return new self('ShippingZone must cover at least one country code.');
    }

    public static function invalidCountryCode(mixed $code): self
    {
        $shown = is_string($code) ? $code : get_debug_type($code);

        return new self("ShippingZone country code \"{$shown}\" is invalid: use an uppercase two-letter code such as \"BG\".");
    }

    public static function duplicateCountryCode(string $code): self
    {
        return new self("ShippingZone country code \"{$code}\" is listed more than once.");
    }

    public static function invalidSettlementName(mixed $name): self
    {
        $shown = is_string($name) ? "\"{$name}\"" : get_debug_type($name);

        return new self("ShippingZone settlement name {$shown} is invalid: each must be a non-empty string.");
    }

    public static function duplicateSettlementName(string $name): self
    {
        return new self("ShippingZone settlement name \"{$name}\" is listed more than once.");
    }

    public static function invalidPostcode(mixed $postcode): self
    {
        $shown = is_string($postcode) ? "\"{$postcode}\"" : get_debug_type($postcode);

        return new self("ShippingZone postcode {$shown} is invalid: after removing whitespace and uppercasing it must be 2 to 12 characters of A-Z, 0-9 and \"-\".");
    }

    public static function duplicatePostcode(string $postcode): self
    {
        return new self("ShippingZone postcode \"{$postcode}\" is listed more than once (after normalization).");
    }
}
