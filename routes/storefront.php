<?php

use App\Http\Controllers\Storefront\CartPageController;
use App\Http\Controllers\Storefront\CategoryController;
use App\Http\Controllers\Storefront\CheckoutPageController;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderReceivedController;
use App\Http\Controllers\Storefront\PageController;
use App\Http\Controllers\Storefront\ProductController;
use App\Storefront\Orders\OrderReceivedHeaders;
use App\Storefront\Theme\ApplyStorefrontTheme;
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
$withoutSession = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
];

Route::middleware([SecurityHeaders::class, ApplyStorefrontTheme::class])
    ->withoutMiddleware($withoutSession)
    ->group(function (): void {
        Route::get('/', [HomeController::class, 'index'])->name('storefront.home');

        Route::get('/product/{slug}', [ProductController::class, 'show'])
            ->where('slug', '[\s\S]*')
            ->name('storefront.product');

        // A server shell filled by the browser from GET /api/cart (S3). `private, no-store`, noindex.
        Route::get('/cart', [CartPageController::class, 'show'])->name('storefront.cart');

        // The one-page checkout (C1): a server shell driven by the browser on the existing API. `private, no-store`, noindex.
        Route::get('/checkout', [CheckoutPageController::class, 'show'])->name('storefront.checkout');

        // A content page (pages-design.md): the prefix belongs to pages alone. The controller decides, so a draft and an unknown slug are the
        // same storefront 404.
        Route::get('/info/{slug}', [PageController::class, 'show'])
            ->where('slug', '[^/]+')
            ->name('storefront.page');

        // The admin's Preview: a temporary SIGNED url, checked in the controller (a bad signature is the storefront's own 404). noindex.
        Route::get('/info-preview/{id}', [PageController::class, 'preview'])
            ->where('id', '[0-9]+')
            ->name('storefront.page.preview');

        // The header builder's preview (D2): the home page with the DRAFT header, a temporary SIGNED url (checked in the controller), noindex, no-store.
        Route::get('/header-preview', [HeaderPreviewController::class, 'show'])->name('storefront.header.preview');

        Route::get('/product-category/{path}', [CategoryController::class, 'show'])
            ->where('path', '[\s\S]*')
            ->name('storefront.category');
    });

/*
| The order-received page (C1, owner decision D1): reached only through the signed, temporary url the checkout returns. Its
| own group because it needs `Referrer-Policy: no-referrer` (the url is the key to the order), which SecurityHeaders would
| overwrite; OrderReceivedHeaders sets the rest of the storefront's headers too. Same cookie-free pipeline. The signature is
| checked in the controller (a failure is the storefront's own 404, not the framework's 403).
*/
Route::middleware([OrderReceivedHeaders::class, ApplyStorefrontTheme::class])
    ->withoutMiddleware($withoutSession)
    ->group(function (): void {
        Route::get('/order/received/{orderNumber}', [OrderReceivedController::class, 'show'])
            ->where('orderNumber', '[\s\S]*')
            ->name('storefront.order.received');
    });
