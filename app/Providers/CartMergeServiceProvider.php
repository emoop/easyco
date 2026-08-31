<?php

namespace App\Providers;

use App\Listeners\MergeGuestCartIntoAccountCart;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers MergeGuestCartIntoAccountCart on Laravel's own
 * Illuminate\Auth\Events\Login — see cart-domain-design.md §8. Uses
 * Event::listen() explicitly, matching this project's existing
 * posture of explicit registration over auto-discovery
 * (App\Providers\CatalogSkuGeneratorServiceProvider/
 * CatalogSlugGeneratorServiceProvider do the same for their own
 * Hook:: listeners).
 */
class CartMergeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Login::class, MergeGuestCartIntoAccountCart::class);
    }
}
