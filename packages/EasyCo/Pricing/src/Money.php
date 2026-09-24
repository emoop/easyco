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

    /**
     * Splits THIS amount into count($weights) shares, in the same
     * currency, proportional to $weights, via the largest-remainder
     * method — operational-sales-domain-design.md §3.13's own "Promotion
     * allocation rule". Introduced for splitting a promotion discount
     * across cart lines (App\Services\PromotionDiscountCalculator) and a
     * future POS bill-level discretionary discount split — one
     * implementation, every caller that needs "divide this exact amount
     * by these weights" reuses it, rather than each caller re-deriving
     * its own (previously-buggy) rounding rule.
     *
     * WHY THIS IS A MONEY OPERATION, NOT A "FRACTION/PERCENTAGE" ONE
     * multiply()'s own docblock rules out: multiplying by a fraction or
     * percentage APPLIES NEW INFORMATION (a rate) to an amount — a
     * genuine pricing/discount concern (Price's own tax math, a future
     * Discount domain). allocate() applies no rate at all: it exactly
     * redistributes an ALREADY-KNOWN total across integer weights, with
     * the one guarantee that matters specifically for money — the parts
     * sum back to the whole, to the minor unit, every time. That is the
     * same class of operation as add()/subtract() (exact, no
     * rounding-introduced value), not the same class as "apply a 10%
     * discount."
     *
     * ALGORITHM — the largest-remainder method. A naive "round each
     * share independently, let the last one absorb the remainder"
     * approach was tried first and rejected: a real counter-example (10%
     * over eligible amounts 5, 5, 1 minor units) produces a NEGATIVE
     * share for the last line under that rule (operational-sales-
     * domain-design.md §3.13's own worked example). This method never
     * can:
     *  1. floor[i] = intdiv(weights[i] x $this->minorValue, sum(weights))
     *     for every i.
     *  2. leftover = $this->minorValue - sum(floor) (an integer,
     *     0 <= leftover < count(weights), by construction).
     *  3. leftover minor units go one each to the weights with the
     *     LARGEST fractional remainder (weights[i] x $this->minorValue
     *     mod sum(weights)), largest first; ties broken by array order
     *     — EXPLICITLY, via a secondary `$a <=> $b` comparison on the
     *     original index, not by relying on usort()'s own stability
     *     (stable since PHP 8.0.0, which this method could have leaned
     *     on, but an explicit tie-break makes the ordering guarantee
     *     visible at the call site instead of depending on a runtime
     *     property of the sort implementation).
     *
     * GUARANTEES: Σ result == $this, exactly, always. Deterministic —
     * the same ($this, $weights) always produces the same output.
     * When $this->minorValue <= sum(weights) (true for every caller in
     * this codebase today — a discount can never exceed its own
     * eligible base), 0 <= result[i] <= weights[i] for every i. A zero
     * weight always receives a zero share (its fractional remainder is
     * always 0, so it can never win a largest-remainder tie against a
     * genuinely weighted line).
     *
     * @param int[] $weights Non-negative integers, in the order the
     *   result is returned in. Not necessarily minor units of anything
     *   in particular — any non-negative integer weighting works.
     * @return self[] Same count and order as $weights, same currency as
     *   $this.
     *
     * @throws InvalidArgumentException Empty $weights; a negative
     *   weight; a negative amount being allocated; weights summing to
     *   zero while the amount is non-zero (nothing to allocate it to —
     *   summing to zero while the amount is ALSO zero returns an
     *   all-zero result instead, a real and valid case); the running sum
     *   of $weights overflowing a PHP integer; or a weight x amount
     *   product that would overflow a PHP integer.
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Money::allocate(): $weights must not be empty.');
        }

        if ($this->minorValue < 0) {
            throw new InvalidArgumentException('Money::allocate(): the amount being allocated must not be negative.');
        }

        $weights = array_values($weights);
        $sumOfWeights = 0;

        foreach ($weights as $index => $weight) {
            if (! is_int($weight) || $weight < 0) {
                throw new InvalidArgumentException(
                    "Money::allocate(): weights[{$index}] must be a non-negative integer."
                );
            }

            if ($sumOfWeights > PHP_INT_MAX - $weight) {
                throw new InvalidArgumentException(
                    'Money::allocate(): the sum of $weights overflows a PHP integer.'
                );
            }

            $sumOfWeights += $weight;
        }

        if ($sumOfWeights === 0) {
            if ($this->minorValue !== 0) {
                throw new InvalidArgumentException(
                    'Money::allocate(): weights sum to zero but the amount to allocate is not zero — '.
                    'there is nothing to allocate it to.'
                );
            }

            return array_map(fn (): self => new self(0, $this->currency), $weights);
        }

        $floors = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            if ($weight !== 0 && $this->minorValue > intdiv(PHP_INT_MAX, $weight)) {
                throw new InvalidArgumentException(
                    "Money::allocate(): weights[{$index}] x amount overflows a PHP integer."
                );
            }

            $product = $weight * $this->minorValue;
            $floors[$index] = intdiv($product, $sumOfWeights);
            $remainders[$index] = $product - ($floors[$index] * $sumOfWeights);
        }

        $leftover = $this->minorValue - array_sum($floors);

        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));

        for ($i = 0; $i < $leftover; $i++) {
            $floors[$order[$i]]++;
        }

        return array_map(fn (int $minorValue): self => new self($minorValue, $this->currency), $floors);
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
