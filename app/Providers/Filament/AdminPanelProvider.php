<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
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
                \App\Http\Middleware\ApplyStoreLocale::class,
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
            ->sidebarCollapsibleOnDesktop();
    }
}
