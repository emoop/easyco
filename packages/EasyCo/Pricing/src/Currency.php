<?php

namespace EasyCo\Pricing;

use InvalidArgumentException;

/**
 * Immutable currency value object.
 *
 * Deliberately NOT a full ISO 4217 database — just enough real, correct
 * currencies to build on, with the number of minor-unit decimal places
 * that actually varies per currency (JPY has 0, KWD has 3, most have 2).
 * Hardcoding "2 decimal places" everywhere is a common bug source once a
 * platform expands beyond EUR/USD-like currencies — Money/Price read the
 * decimal count from here instead.
 *
 * Extending the list later (or backing it with config/a database) does not
 * require any change to Money or Price — they only depend on this class's
 * public API, not on how the table is populated.
 */
final class Currency
{
    /** @var array<string, int> ISO 4217 code => minor-unit decimal places */
    private const KNOWN = [
        'EUR' => 2, 'USD' => 2, 'GBP' => 2, 'BGN' => 2, 'CHF' => 2,
        'CZK' => 2, 'PLN' => 2, 'RON' => 2, 'HUF' => 2,
        'SEK' => 2, 'NOK' => 2, 'DKK' => 2,
        'CAD' => 2, 'AUD' => 2, 'CNY' => 2, 'INR' => 2, 'TRY' => 2,
        'JPY' => 0, 'KRW' => 0,
        'KWD' => 3, 'BHD' => 3, 'OMR' => 3,
    ];

    private function __construct(
        private readonly string $code,
        private readonly int $decimalPlaces,
    ) {
    }

    public static function of(string $code): self
    {
        $normalized = strtoupper(trim($code));

        if (! isset(self::KNOWN[$normalized])) {
            throw new InvalidArgumentException(
                "Unsupported currency code: \"{$code}\". Known codes: ".implode(', ', array_keys(self::KNOWN))
            );
        }

        return new self($normalized, self::KNOWN[$normalized]);
    }

    // Small convenience sugar for the currencies EasyCo will use most.
    public static function EUR(): self
    {
        return self::of('EUR');
    }

    public static function USD(): self
    {
        return self::of('USD');
    }

    public static function BGN(): self
    {
        return self::of('BGN');
    }

    public static function GBP(): self
    {
        return self::of('GBP');
    }

    public function code(): string
    {
        return $this->code;
    }

    public function decimalPlaces(): int
    {
        return $this->decimalPlaces;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    /**
     * Accepts either a Currency instance or an ISO code string — small
     * convenience used internally by Money/Price so callers can pass
     * either 'EUR' or Currency::EUR() interchangeably.
     */
    public static function from(self|string $currency): self
    {
        return $currency instanceof self ? $currency : self::of($currency);
    }
}
