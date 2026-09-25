<?php

namespace App\Providers;

use App\Sandbox\Http\Controllers\SandboxProductController;
use App\Sandbox\Http\Middleware\NoIndexHeaders;
use App\Sandbox\SandboxProductListPage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the sandbox storefront's three routes — Prompt D, D1/D2.
 *
 * WHY A PROVIDER AND NOT AN IF INSIDE routes/web.php: D1's own
 * requirement is "disabled -> the routes do not exist (404), not a
 * runtime check inside a controller". A conditional in routes/web.php
 * would still load web.php (and therefore still have to be read, kept,
 * and reasoned about by anyone auditing the real storefront's routes) on
 * every single request in every environment. Registering the group from
 * a dedicated provider means the real storefront's route file never
 * mentions the sandbox at all, and the sandbox's routes genuinely do not
 * exist in the router's table when the flag is off — confirmed by
 * SandboxRoutesDisabledTest asserting `Route::has('sandbox.index')` is
 * false, not merely that a request 404s.
 *
 * ONE GATE, ONE PLACE (sandboxRoutesEnabled()) — the flag AND
 * "not production". Both are read here, at registration time, never in a
 * controller and never per request:
 * - config('sandbox.enabled') — config/sandbox.php, defaults to false.
 * - ! app()->isProduction() — a second, independent condition, so a
 *   production deployment that sets EASYCO_SANDBOX=true by accident
 *   still exposes nothing.
 *
 * NO SEPARATE routes/sandbox.php FILE, deliberately: D2 requires the
 * whole sandbox to be removable by deleting its folders, its provider,
 * its config and its registration. A fourth file to delete (and to keep
 * out of route caching surprises) buys nothing over the three lines
 * below, which are already the entire route table this feature has.
 *
 * THE GROUP'S MIDDLEWARE: 'web' (the same group the real storefront's
 * future pages will use — sessions/locale/CSRF) plus NoIndexHeaders,
 * which puts `X-Robots-Tag: noindex` on every response this group
 * produces. The sandbox is a preview of real merchant data: it must
 * never be indexed, and the header is the one mechanism that also covers
 * a response nobody renders a <head> for.
 */
class SandboxServiceProvider extends ServiceProvider
{
    /**
     * The list page's media disk, injected rather than read inside the
     * class — the exact "config is read only at the wiring boundary,
     * never inside the class body" pattern
     * MediaControllerServiceProvider already establishes for
     * MediaController's own three config values (that class's own
     * docblock). SandboxProductListPage only ever needs the PATH of each
     * product's first image (its subquery selects no disk column), so
     * there is no MediaAsset object there that could report its own disk
     * — unlike the product page's gallery, which has one and uses
     * MediaAsset::disk().
     */
    public function register(): void
    {
        $this->app->when(SandboxProductListPage::class)
            ->needs('$mediaDisk')
            ->give(fn (): string => (string) config('services.media.default_disk', 'public'));
    }

    public function boot(): void
    {
        $this->registerSandboxRoutes();
    }

    /**
     * D1's gate, exposed as a real method rather than inlined in
     * registerSandboxRoutes() so the production branch has one, direct,
     * named thing a test can assert on — see
     * SandboxRoutesProductionTest, which runs its whole test method in a
     * production environment on purpose.
     */
    public function sandboxRoutesEnabled(): bool
    {
        return (bool) config('sandbox.enabled', false) && ! app()->isProduction();
    }

    /**
     * Public because a test in the 'testing' environment asserts BOTH
     * halves of the gate on the REAL boot path: with EASYCO_SANDBOX=true
     * set before the application boots, the routes exist
     * (SandboxProductListTest/SandboxProductPageTest — they call no
     * registration method at all, they just make real requests); with the
     * same flag set and APP_ENV=production (so the flag's second
     * condition is the only thing that can refuse),
     * registerSandboxRoutes() is called directly and must register
     * nothing.
     */
    public function registerSandboxRoutes(): void
    {
        if (! $this->sandboxRoutesEnabled()) {
            return;
        }

        Route::middleware(['web', NoIndexHeaders::class])
            ->prefix('_sandbox')
            ->name('sandbox.')
            ->group(function (): void {
                Route::get('/', [SandboxProductController::class, 'index'])->name('index');
                Route::get('/products/{productId}', [SandboxProductController::class, 'show'])->name('products.show');
            });
    }
}
