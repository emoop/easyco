<?php

namespace Tests\Unit;

use App\Services\CumulativeRefundShareCalculator;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * operational-sales-domain-design.md §3.13's "Partial return of a
 * quantity" — the cumulative-share formula, pure and stateless, no
 * database needed (App\Services\CumulativeRefundShareCalculator's own
 * docblock).
 */
final class CumulativeRefundShareCalculatorTest extends TestCase
{
    private function calculator(): CumulativeRefundShareCalculator
    {
        return new CumulativeRefundShareCalculator();
    }

    private function money(int $minorUnits, string $currency = 'EUR'): Money
    {
        return Money::fromMinorUnits($minorUnits, $currency);
    }

    /**
     * §3.13's own worked example, verbatim: quantity 3, net 100.00 (10000
     * minor units) — cumulative(0)=0, cumulative(1)=3333, cumulative(2)=6666,
     * cumulative(3)=10000. Returning one unit at a time: 3333, 3333, 3334.
     */
    public function test_the_worked_example_returning_one_unit_at_a_time(): void
    {
        $net = $this->money(10000);

        $first = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 0, thisReturn: 1);
        $second = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 1, thisReturn: 1);
        $third = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 2, thisReturn: 1);

        $this->assertSame(3333, $first->minorValue());
        $this->assertSame(3333, $second->minorValue());
        $this->assertSame(3334, $third->minorValue());
        $this->assertSame(10000, $first->minorValue() + $second->minorValue() + $third->minorValue());
    }

    /** Same worked example, returning two units then one — still sums to 10000, a different split. */
    public function test_the_worked_example_returning_two_units_then_one(): void
    {
        $net = $this->money(10000);

        $twoAtOnce = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 0, thisReturn: 2);
        $lastOne = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 2, thisReturn: 1);

        $this->assertSame(6666, $twoAtOnce->minorValue());
        $this->assertSame(3334, $lastOne->minorValue());
        $this->assertSame(10000, $twoAtOnce->minorValue() + $lastOne->minorValue());
    }

    public function test_a_full_return_in_one_call_equals_exactly_net_paid_amount(): void
    {
        $net = $this->money(10000);

        $share = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 0, thisReturn: 3);

        $this->assertTrue($share->equals($net));
    }

    public function test_two_sequential_partial_returns_sum_to_the_same_total_a_single_full_return_would(): void
    {
        $net = $this->money(10000);

        $full = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 0, thisReturn: 3);

        $partial1 = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 0, thisReturn: 1);
        $partial2 = $this->calculator()->shareFor($net, originalQuantity: 3, alreadyReturned: 1, thisReturn: 2);

        $this->assertSame($full->minorValue(), $partial1->minorValue() + $partial2->minorValue());
    }

    /** A genuinely non-dividing case: quantity 7, net 100 minor units — proves floor(), not round(). */
    public function test_a_non_dividing_quantity_proves_the_floor_behaviour(): void
    {
        $net = $this->money(100);

        // cumulative(1) = floor(100*1/7) = floor(14.28) = 14.
        $share = $this->calculator()->shareFor($net, originalQuantity: 7, alreadyReturned: 0, thisReturn: 1);
        $this->assertSame(14, $share->minorValue());

        // Returning all 7 must still sum to exactly 100, one unit at a time.
        $sum = 0;
        for ($returned = 0; $returned < 7; $returned++) {
            $sum += $this->calculator()->shareFor($net, originalQuantity: 7, alreadyReturned: $returned, thisReturn: 1)->minorValue();
        }
        $this->assertSame(100, $sum);
    }

    public function test_thisReturn_of_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a positive integer');

        $this->calculator()->shareFor($this->money(10000), 3, 0, 0);
    }

    public function test_a_negative_thisReturn_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a positive integer');

        $this->calculator()->shareFor($this->money(10000), 3, 0, -1);
    }

    public function test_a_negative_alreadyReturned_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be negative');

        $this->calculator()->shareFor($this->money(10000), 3, -1, 1);
    }

    public function test_a_return_that_would_exceed_the_original_quantity_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds originalQuantity');

        $this->calculator()->shareFor($this->money(10000), 3, 2, 2);
    }

    public function test_the_result_currency_matches_net_paid_amounts_own_currency(): void
    {
        $share = $this->calculator()->shareFor($this->money(300, 'BGN'), 3, 0, 1);

        $this->assertSame('BGN', $share->currency()->code());
    }
}
