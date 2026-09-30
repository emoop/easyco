<?php

use App\Http\Middleware\ApplyStoreLocale;
use App\Http\Middleware\ApplyStoreTimezone;
use App\Http\Middleware\EnsureStaffHasPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (stateful) mode — see account-domain-design.md §6.
        // Conditionally injects the session/cookie/CSRF pipeline for
        // requests recognized as "from the frontend" (Referer/Origin
        // matching config('sanctum.stateful')); every other request
        // stays on the default stateless api pipeline.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Site Settings' first real consumer (site-settings-design.md)
        // — applies the merchant-configured storefront locale on every
        // 'web'-group request (the future storefront). This is a
        // SEPARATE registration from AdminPanelProvider's own
        // ->middleware([...]) array — confirmed directly against the
        // installed Filament v5.8.1 source that panel routes never run
        // through this 'web' group at all, so the admin panel needs its
        // own copy of this middleware to get the same locale applied.
        $middleware->web(append: [
            ApplyStoreLocale::class,
            // The same mechanism's second consumer: the merchant's own time
            // zone, applied to Filament's own global timezone manager so
            // every date/time the panel DISPLAYS uses it (site.timezone).
            // Registered here, in the 'web' group, for a reason that is NOT
            // symmetry with the locale middleware above but an independently
            // verified fact: Livewire's own update endpoint — the pipeline
            // every table sort, pagination click and form save round trip
            // goes through on a panel page — is registered with exactly
            // `->middleware(['web', RequireLivewireHeaders::class])`
            // (vendor/livewire/livewire/src/Mechanisms/HandleRequests/
            // HandleRequests.php), i.e. this group, never the panel's own
            // middleware array. See ApplyStoreTimezone's class docblock.
            ApplyStoreTimezone::class,
        ]);

        // The merchant-surface permission-enforcement point —
        // staff-access-domain-design.md §1/§5. Used as
        // `staff.can:product_manage` alongside `auth:staff` on a route.
        $middleware->alias([
            'staff.can' => EnsureStaffHasPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
