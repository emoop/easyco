<?php

namespace Tests\Unit;

use App\Services\PromotionDiscountResult;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * PromotionDiscountResult's own constructor invariant — a cheap
 * corruption detector (see that class's own docblock): perLineShares
 * must be non-empty and must sum to exactly amount(), in the same
 * currency. PromotionDiscountCalculator's real allocate()-based
 * construction should never actually trip this; these tests exercise
 * the guard directly via the public factories, not through the
 * calculator.
 */
final class PromotionDiscountResultTest extends TestCase
{
    private function money(int $minorUnits, string $currency = 'EUR'): Money
    {
        return Money::fromMinorUnits($minorUnits, $currency);
    }

    public function test_uncapped_accepts_shares_that_sum_to_amount(): void
    {
        $result = PromotionDiscountResult::uncapped(
            $this->money(100),
            [$this->money(60), $this->money(40)],
        );

        $this->assertSame(100, $result->amount()->minorValue());
        $this->assertSame([60, 40], array_map(fn (Money $m) => $m->minorValue(), $result->perLineShares()));
    }

    public function test_capped_accepts_shares_that_sum_to_amount(): void
    {
        $result = PromotionDiscountResult::capped(
            $this->money(100),
            $this->money(500),
            [$this->money(100)],
        );

        $this->assertTrue($result->discountCapped());
        $this->assertSame(500, $result->nominalAmount()->minorValue());
    }

    public function test_rejects_empty_per_line_shares(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PromotionDiscountResult::uncapped($this->money(100), []);
    }

    public function test_rejects_shares_that_do_not_sum_to_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PromotionDiscountResult::uncapped($this->money(100), [$this->money(60), $this->money(30)]);
    }

    public function test_rejects_shares_summing_to_amount_in_the_wrong_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PromotionDiscountResult::uncapped($this->money(100, 'EUR'), [$this->money(100, 'USD')]);
    }
}
