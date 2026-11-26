<?php

namespace EasyCo\CourierEcont\Providers;

use EasyCo\CourierEcont\EcontCatalogue;
use EasyCo\CourierEcont\EcontDescriptor;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use Illuminate\Support\ServiceProvider;

/** Installs Econt (courier-integration-design.md §2.1): registered, not enabled; the merchant switches it on in «Куриери». Same shape as the BOX NOW provider. */
class CourierEcontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CarrierCapability::PICKUP->containerKey(EcontDescriptor::CODE), EcontCatalogue::class);
        $this->app->bind(CarrierRegistration::descriptorKey(EcontDescriptor::CODE), EcontDescriptor::class);
    }

    public function boot(): void
    {
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration(EcontDescriptor::CODE, 'Econt', [CarrierCapability::PICKUP]));
    }
}
