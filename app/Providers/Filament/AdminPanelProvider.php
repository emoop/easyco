<?php

namespace App\Providers\Filament;

use App\Filament\NavigationGroup;
use App\Http\Middleware\ApplyStoreLocale;
use App\Http\Middleware\ApplyStoreTimezone;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Plain static files under public/, not Vite-built/published assets —
     * no `php artisan filament:assets` publish step needed. Both are small
     * and single-purpose (one file per surface, registered panel-wide):
     *
     *   - admin-product-media-gallery: horizontal padding around
     *     ProductResource's own main photo/video "hero" upload tiles (see
     *     ProductResource::mainPhotoComponents()/videoComponents()'s
     *     `.ec-product-main-photo`/`.ec-product-video` class hooks).
     *   - admin-order-view: vertically centres the value of the order View
     *     page's inline "label: value" pairs against its label
     *     (OrderResource's own PAIR_STYLE flex rows), a tweak the pair's
     *     inline style alone cannot reach because the value lives in
     *     Filament's own content column.
     */
    public function boot(): void
    {
        FilamentAsset::register([
            Css::make('admin-product-media-gallery', asset('css/admin/product-media-gallery.css')),
            Css::make('admin-order-view', asset('css/admin/order-view.css')),
        ]);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // The existing `staff` guard (staff-access-domain-design.md
            // Part 2), never Filament's own default `web` guard — see
            // admin-panel-design.md §3. This is the single most
            // important line in this provider: it is what makes
            // StaffModel, not a `web`-guard User, the identity Filament's
            // own login page resolves against.
            ->authGuard('staff')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Registers the SAME App\Filament\NavigationGroup enum every
            // Resource/Page already returns from getNavigationGroup(), built
            // via NavigationGroup::navigationGroups() as collapsed groups with
            // LAZY translated labels. Registration is what makes the
            // collapsed-by-default state take effect at all: the unregistered
            // path never consults the enum's Collapsible contract (confirmed
            // in v5.8.1's NavigationManager::get()). The lazy labels are what
            // keep the group names translated at render time instead of frozen
            // at boot — see that method's own docblock. Group render order is
            // unaffected: NavigationManager still sorts groups by each enum
            // case's own position among cases().
            ->navigationGroups(NavigationGroup::navigationGroups())
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Site Settings' first real consumer — confirmed
                // directly against the installed Filament v5.8.1
                // source that this panel's routes never run through
                // Laravel's global 'web' middleware group at all (they
                // use exactly this array instead), so this must be
                // registered here too, separately from
                // bootstrap/app.php's own 'web'-group registration, for
                // the admin panel to see the same merchant-configured
                // locale a future storefront request would.
                ApplyStoreLocale::class,
                // And Site Settings' second consumer, for a reason verified
                // independently against the installed v5.8.1 and Livewire
                // sources rather than copied from the line above: a panel
                // page's own GET request runs through THIS array and never
                // through Laravel's 'web' group, so without this line the
                // first render of every screen would be in UTC while every
                // Livewire round trip after it (which uses the 'web' group —
                // see bootstrap/app.php's own comment) would be correct.
                // Both registrations are load-bearing, one per pipeline.
                ApplyStoreTimezone::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->favicon(asset('favicon.svg'))
            ->brandLogo(asset('images/logo.svg'))
            // Narrower than Filament's own 20rem default, plus
            // desktop collapse-to-icon-only with expand-on-demand —
            // both real, direct fluent Panel methods (confirmed
            // against the installed v5.8.1 source; no CSS custom-
            // property override needed). sidebarCollapsibleOnDesktop()
            // specifically (not sidebarFullyCollapsibleOnDesktop(),
            // which hides the sidebar completely) — this task's own
            // "collapses to icon-only" requirement.
            ->sidebarWidth('17rem')
            ->sidebarCollapsibleOnDesktop()
            // Filament already ships the light/dark/system switcher
            // (<x-filament-panels::theme-switcher />), but out of the box the
            // user-menu dropdown is the ONLY place it ever appears — hidden
            // behind the avatar rather than in the top bar, which is where
            // this panel wants it. This line switches that one dropdown
            // rendering off so the same Filament component can be re-rendered
            // by the render hook below instead of appearing twice. Verified
            // against the installed v5.8.1 source that user-menu.blade.php is
            // the single consumer of hasThemeSwitcher(), so nothing else
            // loses anything: dark mode itself stays enabled (HasDarkMode's
            // own default), and that — not this flag — is what includes
            // Filament's dark-mode.js and its localStorage-backed theme
            // store.
            ->themeSwitcher(false)
            // The switcher plus the "View store" icon link, in the top bar
            // immediately before the avatar. USER_MENU_BEFORE is the precise
            // hook for that position: it renders as the first thing inside
            // <x-filament-panels::user-menu />, so it sits directly before
            // the avatar's own dropdown even on the days when a
            // topbar-positioned global search or database notifications would
            // otherwise come between them (something GLOBAL_SEARCH_AFTER
            // cannot promise).
            //
            // Registered on the panel itself with Filament's default
            // (all-panels) hook scope on purpose: the topbar and user-menu
            // blades call renderHook() without any scopes, and
            // ViewManager::renderHook() Arr::wrap()s that null into an empty
            // list — so the default scope is the only one ever consulted for
            // this hook and a panel-scoped registration here would silently
            // never render.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => view('filament.admin.topbar-actions'),
            );
    }
}
