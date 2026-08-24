<?php

namespace EasyCo\Pricing;

use InvalidArgumentException;

/**
 * Immutable monetary value: an integer amount in minor units (cents,
 * stotinki, ...) plus its Currency.
 *
 * WHY MINOR UNITS, NOT FLOAT:
 * Floating-point numbers cannot represent most decimal fractions exactly
 * (0.1 + 0.2 !== 0.3 in binary floating point). Money that has been through
 * even a few float additions/multiplications can silently drift by a cent
 * over many operations. Storing everything as an integer count of the
 * smallest currency unit makes every operation exact.
 *
 * WHY fromDecimal() DOES NOT CAST TO (float):
 * `(float) "19.99"` already re-introduces the exact problem above before
 * we ever get to multiply anything. Decimal strings are parsed digit by
 * digit instead, so `Money::fromDecimal('19.99', 'EUR')` is guaranteed to
 * produce exactly 1999, never 1998 or 2000 due to float rounding.
 *
 * WHY NO CURRENCY CONVERSION HERE:
 * Converting between currencies needs an exchange rate, a rate timestamp,
 * and a policy for where that rate comes from — none of that is a property
 * of a single monetary amount. That belongs to a future exchange-rate
 * service that produces a *new* Money in a different Currency; Money
 * itself only ever represents one already-settled amount in one currency.
 */
final class Money
{
    private function __construct(
        private readonly int $minorValue,
        private readonly Currency $currency,
    ) {
    }

    public static function fromMinorUnits(int $minorValue, Currency|string $currency): self
    {
        return new self($minorValue, Currency::from($currency));
    }

    /**
     * Parses a decimal string (e.g. "19.99", "-3.5", "10") into minor units
     * without ever converting it through PHP float arithmetic.
     */
    public static function fromDecimal(string $decimal, Currency|string $currency): self
    {
        $currency = Currency::from($currency);
        $minorValue = self::decimalStringToMinorUnits(trim($decimal), $currency->decimalPlaces());

        return new self($minorValue, $currency);
    }

    public static function zero(Currency|string $currency): self
    {
        return new self(0, Currency::from($currency));
    }

    public function minorValue(): int
    {
        return $this->minorValue;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * Deterministic decimal string, e.g. 1999 minor units in EUR => "19.99".
     * Decimal-place count comes from the Currency, never hardcoded.
     */
    public function decimalValue(): string
    {
        $places = $this->currency->decimalPlaces();
        $negative = $this->minorValue < 0;
        $digits = str_pad((string) abs($this->minorValue), $places + 1, '0', STR_PAD_LEFT);

        if ($places === 0) {
            return ($negative ? '-' : '').$digits;
        }

        $intPart = substr($digits, 0, -$places);
        $fracPart = substr($digits, -$places);

        return ($negative ? '-' : '').$intPart.'.'.$fracPart;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorValue + $other->minorValue, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorValue - $other->minorValue, $this->currency);
    }

    /**
     * Scales by an integer factor only (e.g. line quantity). Multiplying
     * money by a fraction/percentage is a pricing/discount concern, not a
     * Money concern — see Price for tax math, and the future Discount
     * domain for percentage-off calculations.
     */
    public function multiply(int $quantity): self
    {
        return new self($this->minorValue * $quantity, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->minorValue === $other->minorValue
            && $this->currency->equals($other->currency);
    }

    public function isZero(): bool
    {
        return $this->minorValue === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorValue > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorValue < 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency->code()} vs {$other->currency->code()}."
            );
        }
    }

    /**
     * Converts a decimal string to an integer count of minor units using
     * only string/integer operations — no float ever touches the value.
     * Excess fractional digits (beyond the currency's decimal places) are
     * rounded half-up, e.g. "19.995" at 2 decimal places => 2000 (20.00).
     */
    private static function decimalStringToMinorUnits(string $decimal, int $decimalPlaces): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Invalid decimal amount: \"{$decimal}\".");
        }

        $negative = $m[1] === '-';
        $intPart = $m[2];
        $fracPart = $m[3] ?? '';

        if (strlen($fracPart) > $decimalPlaces) {
            $kept = substr($fracPart, 0, $decimalPlaces);
            $roundUp = $fracPart[$decimalPlaces] >= '5';

            $combined = $intPart.$kept;
            if ($roundUp) {
                $combined = self::incrementDigitString($combined);
            }

            if ($decimalPlaces > 0) {
                $fracPart = substr($combined, -$decimalPlaces);
                $intPart = substr($combined, 0, -$decimalPlaces);
                $intPart = $intPart === '' ? '0' : $intPart;
            } else {
                $intPart = $combined;
                $fracPart = '';
            }
        } else {
            $fracPart = str_pad($fracPart, $decimalPlaces, '0');
        }

        $combinedDigits = ltrim($intPart.$fracPart, '0');
        $combinedDigits = $combinedDigits === '' ? '0' : $combinedDigits;

        $minorValue = (int) $combinedDigits;

        return $negative ? -$minorValue : $minorValue;
    }

    /**
     * Adds 1 to a string of digits using manual carry propagation
     * (e.g. "1999" => "2000"). Pure integer/string logic, no float.
     */
    private static function incrementDigitString(string $digits): string
    {
        $chars = str_split($digits);

        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] === '9') {
                $chars[$i] = '0';

                continue;
            }

            $chars[$i] = (string) ((int) $chars[$i] + 1);

            return implode('', $chars);
        }

        return '1'.implode('', $chars);
    }
}
