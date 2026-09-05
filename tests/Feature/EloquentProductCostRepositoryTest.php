<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\ProductCost;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentProductCostRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): ProductCostRepository
    {
        return app(ProductCostRepository::class);
    }

    public function test_save_then_find_by_priceable_id_and_currency_round_trips_correctly(): void
    {
        $productCost = new ProductCost(
            id: null,
            priceableId: 'variation-1',
            cost: Money::fromDecimal('5.00', 'EUR'),
        );

        $this->repository()->save($productCost);
        $this->assertNotNull($productCost->id());

        $reloaded = $this->repository()->findByPriceableIdAndCurrency('variation-1', 'EUR');
        $this->assertNotNull($reloaded);
        $this->assertSame('variation-1', $reloaded->priceableId());
        $this->assertSame(500, $reloaded->cost()->minorValue());
        $this->assertSame('EUR', $reloaded->cost()->currency()->code());
    }

    /**
     * The whole point of §9.1: no row = no cost recorded, a real
     * "unknown," not zero and not an exception.
     */
    public function test_find_by_priceable_id_and_currency_for_a_priceable_with_no_recorded_cost_returns_null(): void
    {
        $this->assertNull($this->repository()->findByPriceableIdAndCurrency('variation-unknown', 'EUR'));
    }

    public function test_a_second_product_cost_for_the_same_priceable_and_currency_pair_is_rejected_by_the_database(): void
    {
        $first = new ProductCost(id: null, priceableId: 'variation-1', cost: Money::fromDecimal('5.00', 'EUR'));
        $this->repository()->save($first);

        $second = new ProductCost(id: null, priceableId: 'variation-1', cost: Money::fromDecimal('6.00', 'EUR'));

        $this->expectException(QueryException::class);

        $this->repository()->save($second);
    }

    public function test_two_different_currencies_for_the_same_priceable_id_both_save_successfully(): void
    {
        $eur = new ProductCost(id: null, priceableId: 'variation-1', cost: Money::fromDecimal('5.00', 'EUR'));
        $bgn = new ProductCost(id: null, priceableId: 'variation-1', cost: Money::fromDecimal('9.78', 'BGN'));

        $this->repository()->save($eur);
        $this->repository()->save($bgn);

        $this->assertNotNull($this->repository()->findByPriceableIdAndCurrency('variation-1', 'EUR'));
        $this->assertNotNull($this->repository()->findByPriceableIdAndCurrency('variation-1', 'BGN'));
    }
}
