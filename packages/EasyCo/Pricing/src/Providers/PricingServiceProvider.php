<?php

namespace EasyCo\Pricing\Providers;

use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Persistence\InMemoryPriceResolver;
use EasyCo\Pricing\Price;
use Illuminate\Support\ServiceProvider;

class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // TEMPORARY — hardcoded single-price seed for the Catalog vertical
        // slice. Replace with a real PriceList-backed resolver once Pricing
        // persistence exists.
        $this->app->singleton(PriceResolver::class, function () {
            return new InMemoryPriceResolver([
                '1' => Price::exclusiveOfTax(
                    Money::fromMinorUnits(2399, Currency::EUR())
                ),
            ]);
        });
    }

    public function boot(): void
    {
        //
    }
}