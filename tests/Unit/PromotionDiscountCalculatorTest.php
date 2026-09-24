<?php

namespace Tests\Unit;

use App\Services\PromotionDiscountCalculator;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // --- §3.13 / §8: per-line breakdown, added on top of the existing,
    // unchanged total — none of the tests above were touched. -----------

    /** @return int[] */
    private function shareMinorValues(array $shares): array
    {
        return array_map(static fn (Money $m) => $m->minorValue(), $shares);
    }

    public function test_percentage_discount_allocates_per_line_shares_summing_to_the_total(): void
    {
        $promotion = $this->percentagePromotion(3000); // 30%
        $lines = [
            $this->line('v1', 1, 500),
            $this->line('v2', 1, 500),
            $this->line('v3', 1, 100),
        ];

        $result = $this->calculator()->calculate($promotion, $lines);

        // base = 1100, 30% -> roundedDivide(1100*3000, 10000) = 330.
        $this->assertSame(330, $result->amount()->minorValue());
        $this->assertCount(3, $result->perLineShares());
        $this->assertSame(330, array_sum($this->shareMinorValues($result->perLineShares())));
    }

    public function test_fixed_amount_uncapped_allocates_per_line_shares_summing_to_the_total(): void
    {
        $promotion = $this->fixedAmountPromotion(Money::fromMinorUnits(500, 'EUR'));
        $lines = [
            $this->line('v1', 1, 700),
            $this->line('v2', 1, 300),
        ];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(500, $result->amount()->minorValue());
        $this->assertSame(500, array_sum($this->shareMinorValues($result->perLineShares())));
    }

    /**
     * The one case §3.13's own design doc calls out as needing NO
     * rounding at all: total == sum(eligible amounts), so
     * Money::allocate() degenerates to share[i] == the line's own
     * eligible amount, exactly.
     */
    public function test_fixed_amount_capped_shares_equal_the_eligible_amounts_exactly(): void
    {
        $promotion = $this->fixedAmountPromotion(Money::fromMinorUnits(10000, 'EUR')); // face value far exceeds the base
        $lines = [
            $this->line('v1', 1, 500),
            $this->line('v2', 1, 200),
        ];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertTrue($result->discountCapped());
        $this->assertSame(700, $result->amount()->minorValue());
        $this->assertSame([500, 200], $this->shareMinorValues($result->perLineShares()));
    }

    /**
     * usage_limit_items = 3 across three lines: line 1 (qty 2) fits
     * entirely, line 2 (qty 2) crosses the limit with only 1 unit of
     * headroom left (unitPrice x 1, not its full lineTotal), line 3 (qty
     * 1) is entirely beyond the exhausted limit — zero eligible amount,
     * so its own share must be exactly zero too, never a rounding
     * artifact.
     */
    public function test_usage_limit_items_crossing_a_line_gives_it_a_partial_share_and_zero_beyond(): void
    {
        $promotion = $this->percentagePromotion(10000, usageLimitItems: 3); // 100%, so shares == eligible amounts exactly
        $lines = [
            $this->line('v1', 2, 500), // fits entirely: eligible 1000
            $this->line('v2', 2, 300), // crosses: eligible 300 (1 unit x 300)
            $this->line('v3', 1, 900), // beyond the limit: eligible 0
        ];

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(1300, $result->amount()->minorValue());
        $this->assertSame([1000, 300, 0], $this->shareMinorValues($result->perLineShares()));
    }

    /**
     * Equivalence, restated as its own explicit property test rather
     * than by editing any test above: every scenario already covered by
     * the pre-existing tests, re-run here purely to assert
     * Σ perLineShares() == amount() — never edited into the original
     * tests themselves.
     */
    public static function equivalenceScenarioProvider(): array
    {
        return [
            'percentage half-up boundary' => [3000, null, [['v1', 1, 5]]],
            'fixed under base' => [null, 500, [['v1', 1, 1000]]],
            'fixed over base (capped)' => [null, 1000, [['v1', 1, 700]]],
            'fixed exactly equal to base' => [null, 700, [['v1', 1, 700]]],
            'usage limit below total quantity' => [10000, null, [['v1', 2, 500], ['v2', 3, 300]], 3],
            'usage limit above total quantity' => [10000, null, [['v1', 2, 500], ['v2', 3, 300]], 10],
            'no usage limit' => [10000, null, [['v1', 2, 500], ['v2', 3, 300]]],
        ];
    }

    #[DataProvider('equivalenceScenarioProvider')]
    public function test_per_line_shares_always_sum_to_exactly_the_total_amount(
        ?int $basisPoints,
        ?int $fixedAmountMinor,
        array $lineSpecs,
        ?int $usageLimitItems = null,
    ): void {
        $promotion = $basisPoints !== null
            ? $this->percentagePromotion($basisPoints, $usageLimitItems)
            : $this->fixedAmountPromotion(Money::fromMinorUnits($fixedAmountMinor, 'EUR'));

        $lines = array_map(fn (array $spec) => $this->line(...$spec), $lineSpecs);

        $result = $this->calculator()->calculate($promotion, $lines);

        $this->assertSame(
            $result->amount()->minorValue(),
            array_sum($this->shareMinorValues($result->perLineShares())),
        );
    }
}
