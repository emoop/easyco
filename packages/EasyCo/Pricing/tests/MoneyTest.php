<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    // --- Construction / parsing -----------------------------------------

    public function test_from_minor_units(): void
    {
        $m = Money::fromMinorUnits(1999, 'EUR');

        $this->assertSame(1999, $m->minorValue());
        $this->assertSame('EUR', $m->currency()->code());
    }

    public function test_from_decimal_basic(): void
    {
        $this->assertSame(1999, Money::fromDecimal('19.99', 'EUR')->minorValue());
    }

    public function test_from_decimal_whole_number(): void
    {
        $this->assertSame(10000, Money::fromDecimal('100.00', 'EUR')->minorValue());
        $this->assertSame(10000, Money::fromDecimal('100', 'EUR')->minorValue());
    }

    public function test_from_decimal_small_amounts(): void
    {
        $this->assertSame(1, Money::fromDecimal('0.01', 'EUR')->minorValue());
        $this->assertSame(10, Money::fromDecimal('0.10', 'EUR')->minorValue());
    }

    public function test_from_decimal_negative(): void
    {
        $this->assertSame(-350, Money::fromDecimal('-3.50', 'EUR')->minorValue());
    }

    public function test_from_decimal_rounds_excess_precision_half_up(): void
    {
        // 19.995 at 2 decimal places rounds up to 20.00 (2000)
        $this->assertSame(2000, Money::fromDecimal('19.995', 'EUR')->minorValue());
        // 19.994 rounds down to 19.99 (1999)
        $this->assertSame(1999, Money::fromDecimal('19.994', 'EUR')->minorValue());
    }

    public function test_from_decimal_carry_propagates_correctly(): void
    {
        // 9.999 rounds to 10.00 — carry must propagate through all 9s
        $this->assertSame(1000, Money::fromDecimal('9.999', 'EUR')->minorValue());
    }

    public function test_from_decimal_zero_decimal_currency(): void
    {
        $this->assertSame(100, Money::fromDecimal('100', 'JPY')->minorValue());
        // fractional yen rounds to whole units
        $this->assertSame(101, Money::fromDecimal('100.5', 'JPY')->minorValue());
    }

    public function test_from_decimal_three_decimal_currency(): void
    {
        $this->assertSame(19990, Money::fromDecimal('19.99', 'KWD')->minorValue());
    }

    public function test_from_decimal_rejects_invalid_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('19.99.99', 'EUR');
    }

    public function test_from_decimal_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('abc', 'EUR');
    }

    public function test_accepts_currency_instance_as_well_as_string(): void
    {
        $m = Money::fromDecimal('10.00', Currency::EUR());

        $this->assertSame('EUR', $m->currency()->code());
    }

    public function test_zero(): void
    {
        $this->assertSame(0, Money::zero('EUR')->minorValue());
        $this->assertTrue(Money::zero('EUR')->isZero());
    }

    // --- Decimal formatting ------------------------------------------------

    public function test_decimal_value_round_trip(): void
    {
        $this->assertSame('19.99', Money::fromDecimal('19.99', 'EUR')->decimalValue());
        $this->assertSame('0.01', Money::fromDecimal('0.01', 'EUR')->decimalValue());
        $this->assertSame('100.00', Money::fromDecimal('100.00', 'EUR')->decimalValue());
    }

    public function test_decimal_value_negative(): void
    {
        $this->assertSame('-3.50', Money::fromDecimal('-3.50', 'EUR')->decimalValue());
    }

    public function test_decimal_value_zero_decimal_currency(): void
    {
        $this->assertSame('100', Money::fromMinorUnits(100, 'JPY')->decimalValue());
    }

    public function test_decimal_value_three_decimal_currency(): void
    {
        $this->assertSame('19.990', Money::fromMinorUnits(19990, 'KWD')->decimalValue());
    }

    // --- Operations ------------------------------------------------------

    public function test_add(): void
    {
        $sum = Money::fromDecimal('10.00', 'EUR')->add(Money::fromDecimal('5.50', 'EUR'));

        $this->assertSame(1550, $sum->minorValue());
    }

    public function test_add_rejects_currency_mismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('10.00', 'EUR')->add(Money::fromDecimal('10.00', 'USD'));
    }

    public function test_subtract(): void
    {
        $diff = Money::fromDecimal('10.00', 'EUR')->subtract(Money::fromDecimal('3.50', 'EUR'));

        $this->assertSame(650, $diff->minorValue());
    }

    public function test_subtract_rejects_currency_mismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('10.00', 'EUR')->subtract(Money::fromDecimal('10.00', 'USD'));
    }

    public function test_multiply_by_integer_quantity(): void
    {
        $line = Money::fromDecimal('5.00', 'EUR')->multiply(3);

        $this->assertSame(1500, $line->minorValue());
    }

    public function test_multiply_by_zero(): void
    {
        $this->assertTrue(Money::fromDecimal('5.00', 'EUR')->multiply(0)->isZero());
    }

    // --- Equality / sign ---------------------------------------------------

    public function test_equals_true_for_same_amount_and_currency(): void
    {
        $this->assertTrue(
            Money::fromDecimal('10.00', 'EUR')->equals(Money::fromDecimal('10.00', 'EUR'))
        );
    }

    public function test_equals_false_for_different_amount(): void
    {
        $this->assertFalse(
            Money::fromDecimal('10.00', 'EUR')->equals(Money::fromDecimal('10.01', 'EUR'))
        );
    }

    public function test_equals_false_for_different_currency(): void
    {
        $this->assertFalse(
            Money::fromDecimal('10.00', 'EUR')->equals(Money::fromDecimal('10.00', 'USD'))
        );
    }

    public function test_is_positive_negative_zero(): void
    {
        $this->assertTrue(Money::fromDecimal('1.00', 'EUR')->isPositive());
        $this->assertFalse(Money::fromDecimal('1.00', 'EUR')->isNegative());

        $this->assertTrue(Money::fromDecimal('-1.00', 'EUR')->isNegative());
        $this->assertFalse(Money::fromDecimal('-1.00', 'EUR')->isPositive());

        $this->assertTrue(Money::zero('EUR')->isZero());
        $this->assertFalse(Money::zero('EUR')->isPositive());
        $this->assertFalse(Money::zero('EUR')->isNegative());
    }

    // --- Immutability ------------------------------------------------------

    public function test_operations_do_not_mutate_original(): void
    {
        $original = Money::fromDecimal('10.00', 'EUR');

        $original->add(Money::fromDecimal('5.00', 'EUR'));
        $original->multiply(10);

        $this->assertSame(1000, $original->minorValue());
    }
}
