<?php

use App\Http\Controllers\Storefront\AccountPageController;
use App\Http\Controllers\Storefront\CardSizesController;
use App\Http\Controllers\Storefront\CartPageController;
use App\Http\Controllers\Storefront\CategoryController;
use App\Http\Controllers\Storefront\CspReportController;
use App\Http\Controllers\Storefront\CheckoutPageController;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Http\Controllers\Storefront\FooterPreviewController;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderReceivedController;
use App\Http\Controllers\Storefront\PageController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Controllers\Storefront\SitemapController;
use App\Http\Controllers\Storefront\SubjectIndexController;
use App\Http\Controllers\Storefront\SubjectListingController;
use App\Http\Controllers\Storefront\TrapController;
use App\Http\Middleware\EnsureAccountSessionIsCurrent;
use App\Http\Middleware\LimitFilteredCategoryRequests;
use App\Http\Middleware\LimitSearchRequests;
use App\Storefront\Account\AccountPageHeaders;
use App\Storefront\Cache\StorefrontCacheHeaders;
use App\Storefront\Orders\OrderReceivedHeaders;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\Theme\ApplyStorefrontTheme;
use App\Storefront\Support\SecurityHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
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

Route::middleware([SecurityHeaders::class, ApplyStorefrontTheme::class, StorefrontCacheHeaders::class])
    ->withoutMiddleware($withoutSession)
    ->group(function (): void {
        Route::get('/', [HomeController::class, 'index'])->name('storefront.home');

        // P8d: the trap URL (logged, answered with the storefront 404, never cacheable, `Disallow`ed in robots.txt)
        Route::get('/__ec/trap', [TrapController::class, 'show'])->name('storefront.trap');

        Route::get('/product/{slug}', [ProductController::class, 'show'])
            ->where('slug', '[\s\S]*')
            ->name('storefront.product');

        // SRCH-1: the results page (search.md). A request with a `q` is limited per IP; while the feature is off the controller answers the storefront's 404.
        Route::get('/search', [SearchController::class, 'show'])->middleware(LimitSearchRequests::class)->name('storefront.search.results');

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
        // The footer builder's preview (FT-1): the home page with the DRAFT footer, the same kind of signed url.
        Route::get('/footer-preview', [FooterPreviewController::class, 'show'])->name('storefront.footer.preview');
        // The Design screen's preview of a zone that has no route of its own (DS-2b: the simplified checkout header on the cart page): the same kind of signed url.
        Route::get('/design-preview', [DesignPreviewController::class, 'show'])->name('storefront.design.preview');

        // CT-3: a request WITH a `filter` parameter is limited per IP (LimitFilteredCategoryRequests); any other category request is not
        Route::get('/product-category/{path}', [CategoryController::class, 'show'])
            ->middleware(LimitFilteredCategoryRequests::class)
            ->where('path', '[\s\S]*')
            ->name('storefront.category');

        // P2: the same listing page for a brand, a season and a tag (P6e-2: and a collection). The slug is a single segment looked up in the database; the same limit for a filtered request as for a category.
        // P6e-2: one route per subject kind of the registry (prefix, controller method and route name are the kind's: /brand/, /season/, /product-tag/, /collection/); the collection answers 404 while its switch is off.
        foreach (CatalogSubjectKind::cases() as $kind) {
            Route::get($kind->urlPrefix().'{slug}', [SubjectListingController::class, $kind->value])->middleware(LimitFilteredCategoryRequests::class)->where('slug', '[^/]+')->name('storefront.'.$kind->value);
        }

        // SEO-1.1: the index pages /brand and /collection (no slug: the literal path, so `{slug}` above never takes them). Brand: always on; collection: 404 while its switch is off.
        Route::get('/brand', [SubjectIndexController::class, 'brands'])->name('storefront.brand.index');
        Route::get('/collection', [SubjectIndexController::class, 'collections'])->name('storefront.collection.index');

        // PC-2: the sizes of a listing page's cards, one cacheable GET for all of them (the browser asks on the first hover or when idle). Sessionless as the rest
        // of this group; the 60/min budget is the route's own inline throttle (the same way the login route has its own).
        Route::get('/storefront/card-sizes', [CardSizesController::class, 'show'])
            ->middleware('throttle:60,1')
            ->name('storefront.card-sizes');

        // P8d.1: EVERY unknown address answers the storefront's own 404 on the same URL (never a redirect), through this group (no session, no cookie, SecurityHeaders, the theme).
        // Exceptions: `api/...` is JSON, `admin...` and `livewire...` are a plain 404 (never the storefront page inside the admin). StorefrontCacheHeaders refuses non-200/301: never cacheable.
        Route::fallback(static function (Request $request) {
            $path = ltrim($request->path(), '/');

            if ($path === 'api' || str_starts_with($path, 'api/')) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            if (preg_match('#^(admin|livewire)([-/]|$)#', $path) === 1) {
                abort(404);
            }

            return NotFoundResponse::make();
        })->name('storefront.fallback');
    });

/*
| SEO-1: the sitemap (seo.md). Sessionless like the storefront; the controller answers 404 while easyco.noindex is on or for a chunk past the last.
*/
// P8b: robots.txt is sessionless too (no cookie) and cacheable; StorefrontCacheHeaders decides per route name which responses the page cache may keep.
Route::withoutMiddleware($withoutSession)->middleware(StorefrontCacheHeaders::class)->group(function (): void {
    Route::get('/robots.txt', fn () => response(\App\Storefront\Seo\RobotsTxt::body(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']))->name('seo.robots');
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('seo.sitemap');
    Route::get('/sitemap/pages.xml', [SitemapController::class, 'pages'])->name('seo.sitemap.pages');
    Route::get('/sitemap/categories.xml', [SitemapController::class, 'categories'])->name('seo.sitemap.categories');
    Route::get('/sitemap/brands.xml', [SitemapController::class, 'brands'])->name('seo.sitemap.brands');
    Route::get('/sitemap/seasons.xml', [SitemapController::class, 'seasons'])->name('seo.sitemap.seasons');
    Route::get('/sitemap/collections.xml', [SitemapController::class, 'collections'])->name('seo.sitemap.collections');
    Route::get('/sitemap/tags.xml', [SitemapController::class, 'tags'])->name('seo.sitemap.tags');
    Route::get('/sitemap/products-{n}.xml', [SitemapController::class, 'products'])->where('n', '[1-9][0-9]{0,6}')->name('seo.sitemap.products');
});

/*
| The order-received page (C1, owner decision D1): reached only through the signed, temporary url the checkout returns. Its
| own group because it needs `Referrer-Policy: no-referrer` (the url is the key to the order), which SecurityHeaders would
| overwrite; OrderReceivedHeaders sets the rest of the storefront's headers too. Same cookie-free pipeline. The signature is
| checked in the controller (a failure is the storefront's own 404, not the framework's 403).
*/
// P8d: SecurityHeaders is listed AFTER OrderReceivedHeaders: the outer class writes last, so Referrer-Policy: no-referrer stays, and the page gets the frame / permissions / CSP headers too
Route::middleware([OrderReceivedHeaders::class, SecurityHeaders::class, ApplyStorefrontTheme::class])
    ->withoutMiddleware($withoutSession)
    ->group(function (): void {
        Route::get('/order/received/{orderNumber}', [OrderReceivedController::class, 'show'])
            ->where('orderNumber', '[\s\S]*')
            ->name('storefront.order.received');
    });

/*
| ACC-3a: the account pages (storefront-account.js drives them on the stateful account API). Never cacheable, noindex. The paths are the ones the account
| MAILS already link to (App\Mail\AccountMails): /account/verify, /account/reset, /account/forgot, /account/email/confirm, /account/login.
| The token pages (reset, verify, email confirm) add `Referrer-Policy: no-referrer`: AccountPageHeaders is listed BEFORE SecurityHeaders so its policy is written last.
| Cookie-free like the rest of the storefront, EXCEPT `/account` itself (below): it must know who is signed in.
*/
Route::middleware([AccountPageHeaders::class, SecurityHeaders::class, ApplyStorefrontTheme::class])
    ->withoutMiddleware($withoutSession)
    ->group(function (): void {
        Route::get('/account/login', [AccountPageController::class, 'login'])->name('storefront.account.login');
        Route::get('/account/register', [AccountPageController::class, 'register'])->name('storefront.account.register');
        Route::get('/account/forgot', [AccountPageController::class, 'forgot'])->name('storefront.account.forgot');
    });

Route::middleware([AccountPageHeaders::class.':no-referrer', SecurityHeaders::class, ApplyStorefrontTheme::class])
    ->withoutMiddleware($withoutSession)
    ->group(function (): void {
        Route::get('/account/reset', [AccountPageController::class, 'reset'])->name('storefront.account.reset');
        Route::get('/account/verify', [AccountPageController::class, 'verify'])->name('storefront.account.verify');
        Route::get('/account/email/confirm', [AccountPageController::class, 'emailConfirm'])->name('storefront.account.email-confirm');
    });

// `/account`: the web group's session stays (a guest is redirected to the login, a customer is greeted); the soft check first: a stale session is a guest.
Route::middleware([EnsureAccountSessionIsCurrent::class.':soft', AccountPageHeaders::class, SecurityHeaders::class, ApplyStorefrontTheme::class])
    ->group(function (): void {
        Route::get('/account', [AccountPageController::class, 'home'])->name('storefront.account');
    });

/*
| P8d: the CSP violation reports (sessionless, no CSRF, never cacheable; answers 204). 30 per minute per IP; the controller refuses a body over 8 KB (413).
*/
Route::withoutMiddleware($withoutSession)->group(function (): void {
    Route::post('/csp-report', [CspReportController::class, 'store'])->middleware('throttle:csp-report')->name('csp-report');
});
