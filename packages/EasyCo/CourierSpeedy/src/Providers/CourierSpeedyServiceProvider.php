<?php

namespace EasyCo\CourierSpeedy\Providers;

use EasyCo\CourierSpeedy\SpeedyCatalogue;
use EasyCo\CourierSpeedy\SpeedyDescriptor;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use Illuminate\Support\ServiceProvider;

/** Installs Speedy (courier-integration-design.md §2.1): registered, not enabled; the merchant switches it on in «Куриери». Same shape as the BOX NOW provider. */
class CourierSpeedyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CarrierCapability::PICKUP->containerKey(SpeedyDescriptor::CODE), SpeedyCatalogue::class);
        $this->app->bind(CarrierRegistration::descriptorKey(SpeedyDescriptor::CODE), SpeedyDescriptor::class);
    }

    public function boot(): void
    {
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration(SpeedyDescriptor::CODE, 'Speedy', [CarrierCapability::PICKUP]));
    }
}
