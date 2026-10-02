<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/**
 * The few field checks every carrier value object shares, in one place. Shape
 * checks only, exactly as ShippingZone does for country codes: whether a code
 * is a REAL country is the application boundary's job (App\Rules\KnownCountryCode),
 * because this package may not depend on the application's country list.
 *
 * Nothing here normalizes (trims, uppercases): a malformed value is refused,
 * never rewritten, so a carrier receives exactly what the caller meant.
 */
final class CarrierValue
{
    public static function country(string $class, string $field, string $value): string
    {
        if (preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw InvalidCarrierDataException::field($class, $field, 'must be an uppercase ISO 3166-1 alpha-2 country code such as "BG"');
        }

        return $value;
    }

    public static function currency(string $class, string $field, string $value): string
    {
        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw InvalidCarrierDataException::field($class, $field, 'must be an uppercase ISO 4217 currency code such as "EUR"');
        }

        return $value;
    }

    public static function nonEmpty(string $class, string $field, string $value, int $maxLength = 255): string
    {
        if (trim($value) === '') {
            throw InvalidCarrierDataException::field($class, $field, 'must not be empty');
        }

        if (mb_strlen($value) > $maxLength) {
            throw InvalidCarrierDataException::field($class, $field, "must not be longer than {$maxLength} characters");
        }

        return $value;
    }

    public static function nonNegative(string $class, string $field, int $value): int
    {
        if ($value < 0) {
            throw InvalidCarrierDataException::field($class, $field, 'must not be negative');
        }

        return $value;
    }

    public static function nonNegativeOrNull(string $class, string $field, ?int $value): ?int
    {
        return $value === null ? null : self::nonNegative($class, $field, $value);
    }
}
