<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\CostPriceProvider;
use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\ProductCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentCostPriceProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): CostPriceProvider
    {
        return app(CostPriceProvider::class);
    }

    public function test_cost_for_returns_null_for_a_priceable_with_no_recorded_row(): void
    {
        $this->assertNull($this->provider()->costFor('variation-unknown', 'EUR'));
    }

    public function test_cost_for_returns_the_right_money_once_a_product_cost_exists(): void
    {
        $productCost = new ProductCost(
            id: null,
            priceableId: 'variation-1',
            cost: Money::fromDecimal('5.00', 'EUR'),
        );
        app(ProductCostRepository::class)->save($productCost);

        $cost = $this->provider()->costFor('variation-1', 'EUR');

        $this->assertNotNull($cost);
        $this->assertSame(500, $cost->minorValue());
        $this->assertSame('EUR', $cost->currency()->code());
    }

    /**
     * Proves the lookup is genuinely pair-scoped, not priceable-only:
     * a cost recorded in EUR must not be returned when BGN is asked for.
     */
    public function test_cost_for_returns_null_for_the_same_priceable_in_a_different_currency(): void
    {
        $productCost = new ProductCost(
            id: null,
            priceableId: 'variation-1',
            cost: Money::fromDecimal('5.00', 'EUR'),
        );
        app(ProductCostRepository::class)->save($productCost);

        $this->assertNull($this->provider()->costFor('variation-1', 'BGN'));
    }
}
