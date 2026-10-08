<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Exceptions\InvalidShippingMethodException;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\Rating\RateRequest;
use EasyCo\Shipping\Rating\ShippingRateCalculator;
use EasyCo\Shipping\ShippingMethod;
use PHPUnit\Framework\TestCase;

/**
 * Stage 5d (shipping-domain-design.md §12.2): the class MODE of a PER_CLASS method. REPLACE is today's behaviour,
 * byte for byte (ShippingRateCalculatorTest is untouched and still green). ADJUST keeps the base price and adds the
 * signed amount of every DISTINCT class present in the cart, once, floored at 0 — owner decision, final.
 */
final class ShippingClassModeTest extends TestCase
{
    private ShippingRateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ShippingRateCalculator();
    }

    /** @param array<string, int> $rates */
    private function adjust(array $rates, int $base = 500, ?int $freeAbove = null): ShippingMethod
    {
        return ShippingMethod::reconstituteFromStorage('1', '1', 'Adjusting', ShippingMethodKind::PER_CLASS, 0, true, $base, $rates, $freeAbove, null, false, ShippingClassMode::ADJUST);
    }

    /** @param array<string, int> $rates */
    private function replace(array $rates, int $base = 500): ShippingMethod
    {
        return ShippingMethod::reconstituteFromStorage('1', '1', 'Replacing', ShippingMethodKind::PER_CLASS, 0, true, $base, $rates, null, null, false);
    }

    /** @param list<array{0: ?string, 1: int}> $lines */
    private function charge(ShippingMethod $method, array $lines, int $goods = 1000): int
    {
        return $this->calculator->rateFor($method, new RateRequest('EUR', $goods, array_map(fn (array $l): RateLine => new RateLine($l[0], $l[1]), $lines)))->amountMinor();
    }

    public function test_the_mode_is_an_enum_with_two_cases_and_replace_is_the_default_everywhere(): void
    {
        $this->assertSame(['replace', 'adjust'], array_map(fn (ShippingClassMode $mode): string => $mode->value, ShippingClassMode::cases()));
        $this->assertSame(ShippingClassMode::REPLACE, ShippingMethod::create('1', 'Flat', ShippingMethodKind::FLAT, 0, true, 100)->classMode());
        $this->assertSame(ShippingClassMode::REPLACE, $this->replace([])->classMode());
        $this->assertSame(ShippingClassMode::REPLACE, ShippingMethod::reconstituteFromStorage('1', '1', 'Old row', ShippingMethodKind::FLAT, 0, true, 100, [], null, null, false)->classMode());
    }

    public function test_adjust_adds_a_surcharge_to_the_base(): void
    {
        $this->assertSame(500 + 2500, $this->charge($this->adjust(['heavy' => 2500]), [['heavy', 1]]));
    }

    public function test_adjust_subtracts_a_discount_from_the_base(): void
    {
        $this->assertSame(500 - 300, $this->charge($this->adjust(['discount' => -300]), [['discount', 1]]));
    }

    public function test_the_owners_example_base_5_heavy_plus_25_discount_minus_3_charges_27(): void
    {
        $method = $this->adjust(['heavy' => 2500, 'discount' => -300]);

        $this->assertSame(2700, $this->charge($method, [['heavy', 1], ['discount', 1]]));
        $this->assertSame(2700, $this->charge($method, [['heavy', 1], ['discount', 1], [null, 1]]), 'a classless item changes nothing');
        $this->assertSame(500, $this->charge($method, [[null, 1]]), 'a cart with only a classless item charges the base');
    }

    public function test_adjust_adds_each_distinct_class_once_regardless_of_quantity(): void
    {
        $method = $this->adjust(['heavy' => 2500]);

        $this->assertSame(3000, $this->charge($method, [['heavy', 1]]));
        $this->assertSame(3000, $this->charge($method, [['heavy', 40]]), 'quantity does not multiply it');
        $this->assertSame(3000, $this->charge($method, [['heavy', 1], ['heavy', 7]]), 'two lines of one class count once');
    }

    public function test_adjust_total_is_never_below_zero(): void
    {
        $this->assertSame(0, $this->charge($this->adjust(['discount' => -900], base: 500), [['discount', 1]]));
        $this->assertSame(0, $this->charge($this->adjust(['a' => -300, 'b' => -300], base: 500), [['a', 1], ['b', 1]]));
    }

    public function test_adjust_sums_the_distinct_classes_it_does_not_take_the_highest_only(): void
    {
        $method = $this->adjust(['a' => 100, 'b' => 200, 'c' => -50], base: 1000);

        $this->assertSame(1000 + 100 + 200 - 50, $this->charge($method, [['a', 1], ['b', 1], ['c', 1]]));
    }

    public function test_a_class_with_no_amount_and_an_unknown_class_add_nothing_in_adjust(): void
    {
        $method = $this->adjust(['heavy' => 2500]);

        $this->assertSame(500, $this->charge($method, [['light', 2]]), 'no fallback bids in this mode');
        $this->assertSame(3000, $this->charge($method, [['heavy', 1], ['unknown', 3]]));
    }

    public function test_the_free_threshold_still_wins_in_adjust_and_is_measured_on_the_goods(): void
    {
        $method = $this->adjust(['heavy' => 2500], freeAbove: 5000);

        $this->assertSame(0, $this->charge($method, [['heavy', 1]], goods: 5000), 'at the threshold: free, whatever the class');
        $this->assertSame(0, $this->charge($method, [['heavy', 1]], goods: 9000));
        $this->assertSame(3000, $this->charge($method, [['heavy', 1]], goods: 4999));

        $rate = $this->calculator->rateFor($method, new RateRequest('EUR', 4000, [new RateLine('heavy', 1)]));
        $this->assertSame(5000, $rate->freeAboveMinor);
        $this->assertSame(1000, $rate->remainingToFreeMinor);
    }

    public function test_replace_is_unchanged_the_most_expensive_class_replaces_the_base(): void
    {
        $method = $this->replace(['small' => 300, 'large' => 700]);

        $this->assertSame(700, $this->charge($method, [['small', 1], ['large', 1]]));
        $this->assertSame(500, $this->charge($method, [[null, 1], ['small', 1]]), 'a classless line bids the base');
        $this->assertSame(700, $this->charge($method, [['large', 9]]));
    }

    public function test_a_negative_class_rate_is_refused_in_replace_and_accepted_in_adjust(): void
    {
        try {
            ShippingMethod::create('1', 'Replacing', ShippingMethodKind::PER_CLASS, 0, true, 500, ['discount' => -300]);
            $this->fail('REPLACE class rates are non-negative.');
        } catch (InvalidShippingMethodException $exception) {
            $this->assertStringContainsString('non-negative', $exception->getMessage());
        }

        $adjusting = ShippingMethod::create('1', 'Adjusting', ShippingMethodKind::PER_CLASS, 0, true, 500, ['discount' => -300], null, null, false, ShippingClassMode::ADJUST);

        $this->assertSame(['discount' => -300], $adjusting->classRates());
    }

    public function test_a_non_integer_class_adjustment_is_refused(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Adjusting', ShippingMethodKind::PER_CLASS, 0, true, 500, ['heavy' => 1.5], null, null, false, ShippingClassMode::ADJUST);
    }

    public function test_adjust_is_allowed_only_on_a_per_class_method(): void
    {
        foreach ([ShippingMethodKind::FLAT, ShippingMethodKind::FREE, ShippingMethodKind::CARRIER] as $kind) {
            try {
                ShippingMethod::create('1', 'Wrong', $kind, 0, true, $kind === ShippingMethodKind::FLAT ? 100 : null, [], null, $kind === ShippingMethodKind::CARRIER ? 'econt' : null, false, ShippingClassMode::ADJUST);
                $this->fail("{$kind->name} cannot ADJUST.");
            } catch (InvalidShippingMethodException $exception) {
                $this->assertStringContainsString('class mode', $exception->getMessage());
            }
        }
    }

    public function test_update_can_switch_the_mode_and_a_rejected_update_leaves_the_method_as_it_was(): void
    {
        $method = ShippingMethod::create('1', 'Switch', ShippingMethodKind::PER_CLASS, 0, true, 500, ['heavy' => 2500]);

        $method->update('Switch', ShippingMethodKind::PER_CLASS, 0, true, 500, ['heavy' => 2500, 'discount' => -300], null, null, false, ShippingClassMode::ADJUST);
        $this->assertSame(ShippingClassMode::ADJUST, $method->classMode());
        $this->assertSame(['discount' => -300, 'heavy' => 2500], $method->classRates());

        try {
            $method->update('Switch', ShippingMethodKind::FLAT, 0, true, 500, [], null, null, false, ShippingClassMode::ADJUST);
            $this->fail('FLAT cannot ADJUST.');
        } catch (InvalidShippingMethodException) {
            // expected
        }

        $this->assertSame(ShippingMethodKind::PER_CLASS, $method->kind(), 'a rejected update changes nothing');
        $this->assertSame(ShippingClassMode::ADJUST, $method->classMode());
    }
}
