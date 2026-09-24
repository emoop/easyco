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

    // --- allocate() (§3.13's largest-remainder method) --------------------

    /** @return int[] */
    private function minorValues(array $moneys): array
    {
        return array_map(static fn (Money $m) => $m->minorValue(), $moneys);
    }

    /**
     * The exact counter-example that disproved the original "round each
     * share, last line absorbs the remainder" rule
     * (operational-sales-domain-design.md §3.13): that rule produced -1
     * for the last line here. The largest-remainder method must not.
     */
    public function test_allocate_the_five_five_one_counter_example(): void
    {
        $shares = Money::fromMinorUnits(1, 'EUR')->allocate([5, 5, 1]);

        $this->assertSame([1, 0, 0], $this->minorValues($shares));
    }

    public function test_allocate_when_total_equals_sum_of_weights_shares_equal_weights_exactly(): void
    {
        $shares = Money::fromMinorUnits(11, 'EUR')->allocate([5, 5, 1]);

        $this->assertSame([5, 5, 1], $this->minorValues($shares));
    }

    public function test_allocate_zero_weight_always_gets_zero_share(): void
    {
        $shares = Money::fromMinorUnits(100, 'EUR')->allocate([0, 100]);

        $this->assertSame([0, 100], $this->minorValues($shares));
    }

    public function test_allocate_zero_weights_with_zero_total_returns_all_zero(): void
    {
        $shares = Money::zero('EUR')->allocate([0, 0, 0]);

        $this->assertSame([0, 0, 0], $this->minorValues($shares));
    }

    /**
     * weights [1,1,1], total 2: each weight's exact share is 2/3 — no
     * floor wins outright (all floor to 0), so BOTH leftover units go to
     * the largest-remainder ties, broken by array order: index 0 then
     * index 1, never index 2.
     */
    public function test_allocate_ties_are_broken_by_array_order(): void
    {
        $shares = Money::fromMinorUnits(2, 'EUR')->allocate([1, 1, 1]);

        $this->assertSame([1, 1, 0], $this->minorValues($shares));
    }

    public function test_allocate_a_single_weight_receives_the_whole_amount(): void
    {
        $shares = Money::fromMinorUnits(777, 'EUR')->allocate([1]);

        $this->assertSame([777], $this->minorValues($shares));
    }

    public function test_allocate_preserves_currency_on_every_share(): void
    {
        $shares = Money::fromMinorUnits(100, 'USD')->allocate([1, 1]);

        $this->assertSame('USD', $shares[0]->currency()->code());
        $this->assertSame('USD', $shares[1]->currency()->code());
    }

    public function test_allocate_rejects_empty_weights(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinorUnits(10, 'EUR')->allocate([]);
    }

    public function test_allocate_rejects_a_negative_weight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinorUnits(10, 'EUR')->allocate([-1, 5]);
    }

    public function test_allocate_rejects_a_negative_total(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinorUnits(-10, 'EUR')->allocate([1, 1]);
    }

    public function test_allocate_rejects_zero_weights_with_a_nonzero_total(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinorUnits(100, 'EUR')->allocate([0, 0]);
    }

    public function test_allocate_rejects_a_weight_that_would_overflow_when_multiplied_by_the_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinorUnits(PHP_INT_MAX, 'EUR')->allocate([PHP_INT_MAX, 1]);
    }

    public function test_allocate_rejects_a_sum_of_weights_that_overflows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinorUnits(1, 'EUR')->allocate([PHP_INT_MAX, PHP_INT_MAX]);
    }

    /**
     * A seeded randomized property test — deterministic across runs (the
     * seed is fixed), not a real source of flakiness. Proves the three
     * guarantees allocate()'s own docblock claims hold across a wide
     * spread of random (total, weights) combinations, not just the
     * handful of hand-picked cases above.
     *
     * A LOCAL \Random\Randomizer, not mt_srand()/mt_rand() — the latter
     * reseeds PHP's own GLOBAL RNG state for the rest of THIS PROCESS,
     * which would leak into any later test in the same run that also
     * happens to use rand()/mt_rand() (directly, or via some dependency)
     * without reseeding itself first — a real, if subtle, form of
     * test-order-dependent flakiness this suite has no business
     * introducing. A locally-scoped, explicitly-seeded engine affects
     * nothing outside this one test method.
     */
    public function test_allocate_properties_hold_across_many_random_cases(): void
    {
        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937(20260924));

        for ($case = 0; $case < 1000; $case++) {
            $weightCount = $randomizer->getInt(1, 8);
            $weights = [];
            for ($i = 0; $i < $weightCount; $i++) {
                $weights[] = $randomizer->getInt(0, 1000);
            }

            $sumOfWeights = array_sum($weights);

            if ($sumOfWeights === 0) {
                // Covered by its own dedicated tests above; skip this
                // draw rather than special-casing it here too.
                continue;
            }

            // Never exceeds sum(weights) — the one real precondition
            // every actual caller in this codebase already guarantees
            // (a discount can never exceed its own eligible base).
            $total = $randomizer->getInt(0, $sumOfWeights);

            $money = Money::fromMinorUnits($total, 'EUR');
            $shares = $money->allocate($weights);

            $this->assertCount($weightCount, $shares, "case {$case}: share count must match weight count");

            $sumOfShares = array_sum($this->minorValues($shares));
            $this->assertSame($total, $sumOfShares, "case {$case}: shares must sum exactly to the total");

            foreach ($shares as $index => $share) {
                $this->assertGreaterThanOrEqual(0, $share->minorValue(), "case {$case}, share {$index}: must not be negative");
                $this->assertLessThanOrEqual($weights[$index], $share->minorValue(), "case {$case}, share {$index}: must not exceed its own weight");
            }

            // Determinism: the same input allocated again produces the
            // identical output.
            $repeat = $money->allocate($weights);
            $this->assertSame($this->minorValues($shares), $this->minorValues($repeat), "case {$case}: must be deterministic");
        }
    }
}
