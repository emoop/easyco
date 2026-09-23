<?php

namespace App\Providers;

use App\Services\ProductPriceRangeProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // This project's first scoped() binding — deliberately NOT
        // singleton(): ProductPriceRangeProvider itself holds no mutable
        // state of its own, but per-REQUEST memoization is exactly what
        // a future admin product-listing grid needs when it calls
        // forProduct()/forProducts() repeatedly while rendering many
        // rows (Prompt B's own scope) — scoped() gives one instance per
        // request/job without any caching logic inside the class itself
        // (its collaborators, PriceRangeResolver/CatalogScopeResolver,
        // are plain bind()s with no cross-call state, so scoping THIS
        // class costs nothing beyond what Laravel already does for a
        // singleton, while never leaking a resolved value across
        // requests in a persistent worker or Octane-style long-lived
        // process — a real singleton() would risk exactly that, serving
        // a stale price to a later, unrelated request).
        $this->app->scoped(ProductPriceRangeProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
