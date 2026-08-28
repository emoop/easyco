<?php

namespace EasyCo\Pricing\Providers;

use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Persistence\Eloquent\EloquentPriceListItemRepository;
use EasyCo\Pricing\Persistence\Eloquent\EloquentPriceListRepository;
use EasyCo\Pricing\Persistence\Eloquent\EloquentPriceListScopeRepository;
use EasyCo\Pricing\Persistence\Eloquent\EloquentPriceResolver;
use Illuminate\Support\ServiceProvider;

class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PriceResolver::class, EloquentPriceResolver::class);
        // bind(), not singleton(), mirroring the three repository bindings
        // below — EloquentPriceResolver holds no state that needs to
        // persist across a request, unlike InMemoryPriceResolver's
        // constructed-once hardcoded seed array (which no longer exists).

        $this->app->bind(PriceListRepository::class, EloquentPriceListRepository::class);
        $this->app->bind(PriceListScopeRepository::class, EloquentPriceListScopeRepository::class);
        $this->app->bind(PriceListItemRepository::class, EloquentPriceListItemRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

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