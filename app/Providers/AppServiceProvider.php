<?php

namespace App\Providers;

use App\Http\ApiRateLimits;
use App\Mail\SendOrderConfirmation;
use App\NeedsAttention\NeedsAttentionSource;
use App\NeedsAttention\OwedRefundSource;
use App\NeedsAttention\PaymentStepUnfinishedSource;
use App\NeedsAttention\ReceiptMismatchSource;
use App\Services\AuthenticatedStaffResolver;
use App\Services\OrderAdminReader;
use App\Services\PriceDisplayFormatter;
use App\Services\ProductPriceRangeProvider;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Order;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Shipping stage 5g-2a: where a pickup point's kind comes from. A client CLAIM today; the courier stage rebinds this to a
        // record-derived source (shipping-domain-design.md 9.3.7).
        $this->app->bind(\App\Services\PickupKindSource::class, \App\Services\ClientClaimedPickupKindSource::class);

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

        // Same real "N rows -> N queries" shape as ProductPriceRangeProvider
        // above, confirmed while building the Orders admin read-path. The memo
        // that finding produced now lives in the SiteSettingsRepository binding
        // instead (scoped(), in SiteSettingsServiceProvider), because it belongs
        // to the SETTINGS, not to this one formatter — PriceDisplayFormatter is
        // stateless now. Its scoped() binding is kept only so the two have one
        // consistent lifetime: removing it would change nothing observable for a
        // stateless service, and this comment would then have nothing to attach
        // the "where did the memo go" answer to.
        $this->app->scoped(PriceDisplayFormatter::class);

        // OrderAdminReader::forOrder()'s own per-instance cache
        // ($orderViewCache) only pays off across separate
        // app(OrderAdminReader::class) calls if they all reach the SAME
        // instance — the Orders View page's infolist has several
        // TextEntry/RepeatableEntry closures that each independently
        // resolve the reader for the same order id (see that method's
        // own docblock).
        $this->app->scoped(OrderAdminReader::class);

        // The fix for the "reload Staff via the repository on every
        // permission check" cost flagged three times over (Staff's own
        // class docblock, EnsureStaffHasPermission's docblock,
        // AuthorizesViaStaffPermission's docblock) — see
        // AuthenticatedStaffResolver's own docblock for the full
        // reasoning, including why scoped() (not singleton()) is the
        // only safe lifetime here too.
        $this->app->scoped(AuthenticatedStaffResolver::class);

        // The "Needs attention" page's sources (shipping-domain-design.md §7.2.20 §6). TAGGED, not
        // listed in the page: the tag's own array order IS the order of the page's sections, and
        // R4b adds its two cash-on-delivery sources here, by this same one line, without touching
        // App\Filament\Pages\NeedsAttention. The page reads them with app()->tagged(TAG).
        // The content pages the storefront reads (pages-design.md section 4): ONE query per request (the footer, the checkout's page lookup
        // and the settings selects all share it), so scoped(): a fresh list for every request and every queue job.
        $this->app->scoped(\App\Storefront\Pages\PageReader::class);

        // D3: the debounced rebuild of the compiled menus keeps its asked-for flag for the whole process.
        $this->app->singleton(\App\Storefront\Menu\MenuRecompiler::class);

        // The order's context (origin, invoice request) is read ONCE per order per request and remembered, and the full client address
        // (order-context-design.md 7.1) is held only for a permitted reader: one instance per request, never shared across requests.
        $this->app->scoped(\App\Services\OrderContextReader::class);

        // Cash-on-delivery protection (cod-protection-design.md, stage C2-B): the active blocks of the order on the page and its matches, read ONCE
        // per request and shared by the block buttons, the hints and the red line of the matches card.
        $this->app->scoped(\App\Services\OrderBlockLookup::class);
        $this->app->scoped(\App\Services\OrderMatchReader::class);

        $this->app->tag([
            OwedRefundSource::class,
            ReceiptMismatchSource::class,
            PaymentStepUnfinishedSource::class,
        ], NeedsAttentionSource::TAG);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The named API rate limiters, in their one central place.
        ApiRateLimits::register();

        // The footer of every storefront page is the `footer` menu (D3): compiled, and read with the appearance (no query of its own).
        \Illuminate\Support\Facades\View::composer('storefront.layout', static function ($view): void {
            // The merchant's appearance (storefront-customization-design.md 3.4): ONE read, cached; the token stylesheet, the font to preload and the banner.
            $view->with('appearance', app(\App\Storefront\Appearance\StorefrontAppearance::class)->forLayout());
        });

        // mail-design.md section 6.1: the order confirmation. The listener only DISPATCHES (after the order
        // transaction committed); domain packages never call Hook:: themselves, so the registration lives here.
        // D3: the compiled menus are rebuilt (debounced) when a page, a category, a product's visibility or a menu picture changes.
        \App\Storefront\Menu\MenuRecompileListeners::register();

        Hook::action('order.placed', static fn (Order $order) => app(SendOrderConfirmation::class)->handle($order));
    }
}
