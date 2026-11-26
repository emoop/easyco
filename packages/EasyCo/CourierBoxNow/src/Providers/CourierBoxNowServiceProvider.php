<?php

namespace EasyCo\CourierBoxNow\Providers;

use EasyCo\CourierBoxNow\BoxNowCatalogue;
use EasyCo\CourierBoxNow\BoxNowDescriptor;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Installs BOX NOW (courier-integration-design.md §2.1). REGISTRATION MEANS INSTALLED, NOT ENABLED: the courier is switched on
 * by the merchant on the «Куриери» page and does nothing before that (no schedule, no request, no resolvable provider).
 *
 * Registered in boot(), after every provider's register(), so the CarrierRegistry singleton is the Shipping package's one instance
 * whatever the discovery order of the packages is.
 */
class CourierBoxNowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CarrierCapability::PICKUP->containerKey(BoxNowDescriptor::CODE), BoxNowCatalogue::class);
        $this->app->bind(CarrierRegistration::descriptorKey(BoxNowDescriptor::CODE), BoxNowDescriptor::class);
    }

    public function boot(): void
    {
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration(BoxNowDescriptor::CODE, 'BOX NOW', [CarrierCapability::PICKUP]));
    }
}
