<?php

namespace EasyCo\Pricing\Providers;

use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
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
        // The one piece of Laravel-specific wiring for EasyCo\Pricing\
        // DefaultCurrency (see that class's docblock): reads the host
        // application's configured default currency and hands it to the
        // framework-agnostic static holder. DefaultCurrency itself never
        // touches config() or any other Laravel API.
        $code = config('services.pricing.default_currency');
        if ($code !== null) {
            DefaultCurrency::set(Currency::of($code));
        }
    }
}