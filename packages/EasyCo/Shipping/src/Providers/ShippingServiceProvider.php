<?php

namespace EasyCo\Shipping\Providers;

use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Matching\BulgarianSettlementNameNormalizer;
use EasyCo\Shipping\Matching\NeutralSettlementNameNormalizer;
use EasyCo\Shipping\Persistence\Eloquent\EloquentShippingClassRepository;
use EasyCo\Shipping\Persistence\Eloquent\EloquentShippingMethodRepository;
use EasyCo\Shipping\Persistence\Eloquent\EloquentShippingZoneRepository;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShippingClassRepository::class, EloquentShippingClassRepository::class);
        $this->app->bind(ShippingZoneRepository::class, EloquentShippingZoneRepository::class);
        $this->app->bind(ShippingMethodRepository::class, EloquentShippingMethodRepository::class);

        // Settlement-name normalizers, one NAMED binding per locale
        // (shipping-domain-design.md §4). The app layer picks by the store's
        // locale; an extension package adds a locale by binding
        // `shipping.settlement_normalizer.<locale>` itself — nothing here lists
        // the locales. `neutral` is what any locale without its own rules gets.
        $this->app->bind('shipping.settlement_normalizer.neutral', NeutralSettlementNameNormalizer::class);
        $this->app->bind('shipping.settlement_normalizer.bg', BulgarianSettlementNameNormalizer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
