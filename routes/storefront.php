<?php

use App\Http\Controllers\Storefront\CategoryController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\ProductController;
use App\Storefront\Support\SecurityHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| The storefront (storefront-design.md §3.4, §5, S2a)
|--------------------------------------------------------------------------
|
| These routes are loaded from routes/web.php, so they start in the `web` group; the group is then STRIPPED of
| everything that needs a session or a cookie: no session, no cookie encryption, no queued cookies, no CSRF check, no
| shared validation errors. Nothing on a storefront page sets a cookie or starts a session (S4 will make these pages
| cacheable; a Set-Cookie would make a proxy pass). Kept from `web`: ApplyStoreLocale (reads the `site.locale` setting, no session) and ApplyStoreTimezone (same).
|
| The constraints are `.*`-wide ON PURPOSE: whatever the path is, the CONTROLLER decides (StorefrontUrls rules inside the
| reader), so an invalid, hidden or missing slug all end in the same storefront 404 body instead of the framework's.
*/
Route::middleware([SecurityHeaders::class])
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->group(function (): void {
        Route::get('/', [HomeController::class, 'index'])->name('storefront.home');

        Route::get('/product/{slug}', [ProductController::class, 'show'])
            ->where('slug', '[\s\S]*')
            ->name('storefront.product');

        Route::get('/product-category/{path}', [CategoryController::class, 'show'])
            ->where('path', '[\s\S]*')
            ->name('storefront.category');
    });
