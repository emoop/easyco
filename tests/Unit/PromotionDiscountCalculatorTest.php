<?php

namespace Tests\Unit;

use App\Services\PromotionDiscountCalculator;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use PHPUnit\Framework\TestCase;

final class PromotionDiscountCalculatorTest extends TestCase
{
    private function calculator(): PromotionDiscountCalculator
    {
        return new PromotionDiscountCalculator();
    }

    private function percentagePromotion(int $basisPoints, ?int $usageLimitItems = null): Promotion
    {
        return Promotion::create(
            code: 'test-'.uniqid(),
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $basisPoints,
            usageLimitItems: $usageLimitItems,
        );
    }

    private function fixedAmountPromotion(Money $discountAmount): Promotion
    {
        return Promotion::create(
            code: 'test-'.uniqid(),
            discountType: PromotionDiscountType::FIXED_AMOUNT,
            discountAmount: $discountAmount,
        );
    }

    /** @return array{variationId: string, quantity: int, unitPrice: Money, lineTotal: Money} */
    private function line(string $variationId, int $quantity, int $unitPriceMinor): array
    {
        return [
            'variationId' => $variationId,
            'quantity' => $quantity,
            'unitPrice' => Money::fromMinorUnits($unitPriceMinor, 'EUR'),
            'lineTotal' => Money::fromMinorUnits($unitPriceMinor * $quantity, 'EUR'),
        ];
    }

    /**
     * 0.05 EUR (5 minor units) at 30% = 0.015 EUR exactly — the same
     * X.XX5 boundary PHP's own round() is documented to mishandle for
     * decimal-float arithmetic (round(0.015, 2) returns 0.01, not the
     * correct 0.02, because 0.015 isn't exactly representable in binary
     * floating point — the same well-known bug class as round(1.005, 2)
     * === 1.0). Our exact integer minor-units math (5 * 3000 = 15000,
     * roundedDivide(15000, 10000): quotient=1, remainder=5000,
     * remainder*2=10000 >= 10000 -> rounds UP to 2) gets this right
     * because it never touches a float at any point — proving this
     * isn't secretly doing float division somewhere.
     */
    public function test_percentage_discount_computes_the_exact_minor_unit_amount_at_a_round_half_up_boundary(): void
    {
        $promotion = $this->percentagePromotion(3000);
        $lines = [$this->line('v1', 1, 5)];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(2, $result->amount()->minorValue());
        $this->assertSame('EUR', $result->amount()->currency()->code());
        $this->assertFalse($result->discountCapped());
        $this->assertNull($result->nominalAmount());
    }

    public function test_fixed_amount_under_the_eligible_base_is_not_capped(): void
    {
        $promotion = $this->fixedAmountPromotion(Money::fromMinorUnits(500, 'EUR'));
        $lines = [$this->line('v1', 1, 1000)];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(500, $result->amount()->minorValue());
        $this->assertFalse($result->discountCapped());
        $this->assertNull($result->nominalAmount());
    }

    public function test_fixed_amount_over_the_eligible_base_is_capped_to_exactly_the_base(): void
    {
        $promotion = $this->fixedAmountPromotion(Money::fromMinorUnits(1000, 'EUR'));
        $lines = [$this->line('v1', 1, 700)];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(700, $result->amount()->minorValue());
        $this->assertTrue($result->discountCapped());
        $this->assertNotNull($result->nominalAmount());
        $this->assertSame(1000, $result->nominalAmount()->minorValue());
    }

    public function test_fixed_amount_exactly_equal_to_the_base_is_not_capped(): void
    {
        $promotion = $this->fixedAmountPromotion(Money::fromMinorUnits(700, 'EUR'));
        $lines = [$this->line('v1', 1, 700)];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(700, $result->amount()->minorValue());
        $this->assertFalse($result->discountCapped());
        $this->assertNull($result->nominalAmount());
    }

    /**
     * usage_limit_items = 3, but the two applicable lines total 5 units
     * (2 + 3). Walking in order: line 1 (qty 2, lineTotal 1000) fits
     * entirely within the limit -> full 1000 contributed, 1 unit of
     * headroom remains. Line 2 (qty 3, unitPrice 300) crosses the
     * limit -> only unitPrice * 1 = 300 contributed, not its full 900
     * lineTotal. Expected base = 1000 + 300 = 1300; with a 100%
     * PERCENTAGE promotion the discount equals the base exactly, so
     * this isolates the usage_limit_items capping from any percentage
     * rounding.
     */
    public function test_usage_limit_items_below_total_applicable_quantity_computes_a_partial_line_contribution(): void
    {
        $promotion = $this->percentagePromotion(10000, usageLimitItems: 3);
        $lines = [
            $this->line('v1', 2, 500),
            $this->line('v2', 3, 300),
        ];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(1300, $result->amount()->minorValue());
    }

    public function test_usage_limit_items_above_total_applicable_quantity_has_no_effect(): void
    {
        $promotion = $this->percentagePromotion(10000, usageLimitItems: 10);
        $lines = [
            $this->line('v1', 2, 500),
            $this->line('v2', 3, 300),
        ];

        $result = $this->calculator()->calculate($promotion, $lines);

        // Uncapped: full base = 1000 + 900 = 1900.
        $this->assertSame(1900, $result->amount()->minorValue());
    }

    public function test_no_usage_limit_items_uses_the_full_uncapped_base(): void
    {
        $promotion = $this->percentagePromotion(10000);
        $lines = [
            $this->line('v1', 2, 500),
            $this->line('v2', 3, 300),
        ];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(1900, $result->amount()->minorValue());
    }
}
