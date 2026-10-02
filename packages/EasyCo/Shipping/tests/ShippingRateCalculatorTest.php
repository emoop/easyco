<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Rating\MethodRate;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\Rating\RateRequest;
use EasyCo\Shipping\Rating\ShippingRateCalculator;
use EasyCo\Shipping\ShippingMethod;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ShippingRateCalculatorTest extends TestCase
{
    private ShippingRateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ShippingRateCalculator();
    }

    /** @param array<string, int> $rates */
    private function perClass(array $rates, int $fallback = 9, ?int $freeAbove = null, string $id = '1', int $sortOrder = 0, bool $active = true): ShippingMethod
    {
        return ShippingMethod::reconstituteFromStorage($id, '1', "m{$id}", ShippingMethodKind::PER_CLASS, $sortOrder, $active, $fallback, $rates, $freeAbove, null, false);
    }

    private function flat(int $amount, ?int $freeAbove = null, string $id = '1', int $sortOrder = 0, bool $active = true): ShippingMethod
    {
        return ShippingMethod::reconstituteFromStorage($id, '1', "m{$id}", ShippingMethodKind::FLAT, $sortOrder, $active, $amount, [], $freeAbove, null, false);
    }

    private function free(string $id = '1', int $sortOrder = 0, bool $active = true): ShippingMethod
    {
        return ShippingMethod::reconstituteFromStorage($id, '1', "m{$id}", ShippingMethodKind::FREE, $sortOrder, $active, null, [], null, null, false);
    }

    private function carrier(string $id = '1', int $sortOrder = 0, ?int $freeAbove = null): ShippingMethod
    {
        return ShippingMethod::reconstituteFromStorage($id, '1', "m{$id}", ShippingMethodKind::CARRIER, $sortOrder, true, null, [], $freeAbove, 'econt', true);
    }

    /** @param list<array{0: ?string, 1: int}> $lines */
    private function request(array $lines, int $goods = 1000): RateRequest
    {
        return new RateRequest('EUR', $goods, array_map(fn (array $l): RateLine => new RateLine($l[0], $l[1]), $lines));
    }

    private function charge(ShippingMethod $method, array $lines, int $goods = 1000): int
    {
        return $this->calculator->rateFor($method, $this->request($lines, $goods))->amountMinor();
    }

    // --- PER_CLASS: the most expensive class, per order -------------------------------------------

    public function test_classes_rated_3_7_and_5_charge_7_not_the_sum_and_not_per_unit(): void
    {
        $method = $this->perClass(['small' => 3, 'large' => 7, 'medium' => 5]);

        $charge = $this->charge($method, [['small', 1], ['large', 1], ['medium', 1]]);

        $this->assertSame(7, $charge);
        $this->assertNotSame(15, $charge, 'not the sum');
    }

    public function test_quantities_do_not_multiply_the_charge(): void
    {
        $method = $this->perClass(['small' => 3, 'large' => 7, 'medium' => 5]);

        $this->assertSame(7, $this->charge($method, [['small', 4], ['large', 7], ['medium', 2]]));
        $this->assertNotSame(49, $this->charge($method, [['large', 7]]), 'not 7 times a quantity of 7');
        $this->assertSame(7, $this->charge($method, [['large', 7]]));
    }

    public function test_a_single_line_takes_its_class_rate(): void
    {
        $this->assertSame(5, $this->charge($this->perClass(['medium' => 5]), [['medium', 3]]));
    }

    public function test_a_line_without_a_class_takes_the_fallback(): void
    {
        $method = $this->perClass(['small' => 3], fallback: 9);

        $this->assertSame(9, $this->charge($method, [[null, 1]]));
        $this->assertSame(9, $this->charge($method, [['', 1]]), 'a blank class is no class');
        $this->assertSame(9, $this->charge($method, [['  ', 1]]));
    }

    public function test_a_class_without_a_rate_on_this_method_takes_the_fallback(): void
    {
        $method = $this->perClass(['small' => 3], fallback: 9);

        $this->assertSame(9, $this->charge($method, [['large', 1]]));
    }

    public function test_an_unknown_class_code_takes_the_fallback(): void
    {
        $method = $this->perClass(['small' => 3], fallback: 4);

        $this->assertSame(4, $this->charge($method, [['made-up-class', 1]]));
        $this->assertSame(4, $this->charge($method, [['SMALL', 1]]), 'codes are compared exactly, never case-folded');
    }

    public function test_the_fallback_competes_with_the_class_rates_for_the_highest(): void
    {
        $method = $this->perClass(['small' => 3], fallback: 9);

        $this->assertSame(9, $this->charge($method, [['small', 1], [null, 1]]), 'the classless line costs the fallback, the highest of the two');
        $this->assertSame(3, $this->charge($this->perClass(['small' => 3], fallback: 1), [['small', 1], [null, 1]]));
    }

    public function test_all_lines_at_rate_0_charge_0(): void
    {
        $method = $this->perClass(['light' => 0, 'tiny' => 0], fallback: 9);

        $this->assertSame(0, $this->charge($method, [['light', 2], ['tiny', 1]]));
    }

    public function test_a_rate_of_0_is_valid_and_loses_to_a_higher_one(): void
    {
        $method = $this->perClass(['light' => 0, 'large' => 7]);

        $this->assertSame(0, $this->charge($method, [['light', 1]]));
        $this->assertSame(7, $this->charge($method, [['light', 1], ['large', 1]]));
    }

    public function test_numeric_looking_class_codes_work_as_keys(): void
    {
        $method = $this->perClass(['3' => 3, '7' => 7, '5' => 5]);

        $this->assertSame(7, $this->charge($method, [['3', 1], ['7', 1], ['5', 1]]));
    }

    // --- the free-shipping threshold ---------------------------------------------------------------

    public function test_flat_is_charged_below_the_threshold_and_free_at_and_above_it(): void
    {
        $method = $this->flat(500, freeAbove: 8000);

        $this->assertSame(500, $this->charge($method, [[null, 1]], goods: 7999));
        $this->assertSame(0, $this->charge($method, [[null, 1]], goods: 8000), 'exactly the threshold is free (>=)');
        $this->assertSame(0, $this->charge($method, [[null, 1]], goods: 8001));
    }

    public function test_the_threshold_applies_to_per_class_too(): void
    {
        $method = $this->perClass(['large' => 700], freeAbove: 8000);

        $this->assertSame(700, $this->charge($method, [['large', 1]], goods: 7999));
        $this->assertSame(0, $this->charge($method, [['large', 1]], goods: 8000));
        $this->assertSame(0, $this->charge($method, [['large', 1]], goods: 8001));
    }

    public function test_without_a_threshold_nothing_is_ever_free_by_size(): void
    {
        $this->assertSame(500, $this->charge($this->flat(500), [[null, 1]], goods: 99999999));
    }

    public function test_a_threshold_of_0_makes_everything_free_including_a_zero_goods_order(): void
    {
        $this->assertSame(0, $this->charge($this->flat(500, freeAbove: 0), [[null, 1]], goods: 0));
    }

    // --- FREE and CARRIER --------------------------------------------------------------------------

    public function test_free_is_0_with_any_cart(): void
    {
        foreach ([0, 1, 5000, 100000000] as $goods) {
            $this->assertSame(0, $this->charge($this->free(), [['heavy', 99], [null, 1]], goods: $goods));
        }
    }

    public function test_a_carrier_method_is_listed_as_needing_a_quote_and_is_never_priced(): void
    {
        $rates = $this->calculator->ratesFor([$this->carrier('7')], $this->request([[null, 1]]));

        $this->assertCount(1, $rates);
        $this->assertTrue($rates[0]->needsQuote());
        $this->assertSame('7', $rates[0]->methodId);
        $this->assertSame('econt', $rates[0]->carrierCode);

        $this->expectException(LogicException::class);
        $rates[0]->amountMinor();
    }

    public function test_a_carrier_methods_threshold_is_not_applied_here(): void
    {
        $rate = $this->calculator->rateFor($this->carrier(freeAbove: 100), $this->request([[null, 1]], goods: 99999));

        $this->assertTrue($rate->needsQuote(), 'how a threshold combines with a live quote is still open');
    }

    public function test_priced_methods_do_not_need_a_quote(): void
    {
        $this->assertFalse($this->calculator->rateFor($this->flat(500), $this->request([[null, 1]]))->needsQuote());
    }

    // --- the service: active only, ordered ------------------------------------------------------------

    public function test_inactive_methods_are_not_returned(): void
    {
        $rates = $this->calculator->ratesFor([
            $this->flat(500, id: '1', active: false),
            $this->flat(700, id: '2'),
            $this->free(id: '3', active: false),
        ], $this->request([[null, 1]]));

        $this->assertSame(['2'], array_map(fn (MethodRate $r): string => $r->methodId, $rates));
    }

    public function test_methods_are_ordered_by_sort_order_then_id_numerically(): void
    {
        $rates = $this->calculator->ratesFor([
            $this->flat(100, id: '10', sortOrder: 1),
            $this->flat(100, id: '2', sortOrder: 1),
            $this->flat(100, id: '5', sortOrder: 0),
            $this->flat(100, id: '1', sortOrder: 2),
        ], $this->request([[null, 1]]));

        $this->assertSame(['5', '2', '10', '1'], array_map(fn (MethodRate $r): string => $r->methodId, $rates), 'sortOrder first; 2 before 10');
    }

    public function test_every_rate_carries_the_requests_currency(): void
    {
        $rates = $this->calculator->ratesFor([$this->flat(100, id: '1'), $this->carrier('2', 1)], $this->request([[null, 1]]));

        $this->assertSame(['EUR', 'EUR'], array_map(fn (MethodRate $r): string => $r->currency, $rates));
    }

    public function test_no_methods_gives_an_empty_list(): void
    {
        $this->assertSame([], $this->calculator->ratesFor([], $this->request([[null, 1]])));
    }

    // --- the request ----------------------------------------------------------------------------------

    public function test_an_empty_line_list_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateRequest('EUR', 1000, []);
    }

    public function test_a_bad_currency_a_negative_basis_and_a_zero_quantity_are_refused(): void
    {
        foreach ([
            fn () => new RateRequest('eur', 1000, [new RateLine(null, 1)]),
            fn () => new RateRequest('EURO', 1000, [new RateLine(null, 1)]),
            fn () => new RateRequest('EUR', -1, [new RateLine(null, 1)]),
            fn () => new RateLine(null, 0),
            fn () => new RateRequest('EUR', 1000, ['not a line']),
        ] as $build) {
            try {
                $build();
                $this->fail('must be refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
