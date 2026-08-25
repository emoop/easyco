<?php

namespace EasyCo\OperationalSales\Providers;

use Illuminate\Support\ServiceProvider;

class OperationalSalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No repository bindings yet — this step implements only the
        // Client domain entity, with no persistence layer. Bindings for
        // ClientRepository (and later Transaction/SaleLine/InstallmentPlan
        // repositories) land once each aggregate's persistence layer is
        // built, mirroring EasyCo\Catalog\Providers\CatalogServiceProvider.
    }

    public function boot(): void
    {
        // No migrations yet — this step has no persistence layer.
    }
}
