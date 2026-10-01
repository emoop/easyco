<?php

namespace EasyCo\Shipping\Providers;

use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
