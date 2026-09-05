<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Money;
use EasyCo\Pricing\ProductCost;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductCostTest extends TestCase
{
    private function cost(string $amount = '5.00', string $currency = 'EUR'): Money
    {
        return Money::fromDecimal($amount, $currency);
    }

    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $cost = $this->cost();

        $productCost = new ProductCost(
            id: null,
            priceableId: 'variation-1',
            cost: $cost,
        );

        $this->assertNull($productCost->id());
        $this->assertSame('variation-1', $productCost->priceableId());
        $this->assertSame($cost, $productCost->cost());
    }

    public function test_empty_priceable_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductCost(id: null, priceableId: '', cost: $this->cost());
    }

    public function test_update_cost_with_the_same_currency_succeeds_and_changes_the_value(): void
    {
        $productCost = new ProductCost(id: null, priceableId: 'variation-1', cost: $this->cost('5.00', 'EUR'));

        $productCost->updateCost($this->cost('7.50', 'EUR'));

        $this->assertSame(750, $productCost->cost()->minorValue());
        $this->assertSame('EUR', $productCost->cost()->currency()->code());
    }

    public function test_update_cost_with_a_different_currency_throws(): void
    {
        $productCost = new ProductCost(id: null, priceableId: 'variation-1', cost: $this->cost('5.00', 'EUR'));

        $this->expectException(InvalidArgumentException::class);

        $productCost->updateCost($this->cost('7.50', 'BGN'));
    }

    public function test_update_cost_with_a_different_currency_leaves_the_original_cost_unchanged(): void
    {
        $productCost = new ProductCost(id: null, priceableId: 'variation-1', cost: $this->cost('5.00', 'EUR'));

        try {
            $productCost->updateCost($this->cost('7.50', 'BGN'));
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(500, $productCost->cost()->minorValue());
        $this->assertSame('EUR', $productCost->cost()->currency()->code());
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $productCost = new ProductCost(id: null, priceableId: 'variation-1', cost: $this->cost());
        $productCost->assignId('1');

        $this->assertSame('1', $productCost->id());

        $this->expectException(LogicException::class);
        $productCost->assignId('2');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $cost = $this->cost('12.34', 'EUR');

        $productCost = ProductCost::reconstituteFromStorage(
            id: '9',
            priceableId: 'variation-9',
            cost: $cost,
        );

        $this->assertSame('9', $productCost->id());
        $this->assertSame('variation-9', $productCost->priceableId());
        $this->assertSame($cost, $productCost->cost());
    }
}
