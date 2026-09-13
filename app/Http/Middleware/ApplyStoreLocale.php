<?php

namespace App\Http\Middleware;

use App\Settings\Contracts\SiteSettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the `site.locale` setting on every request and applies it via
 * App::setLocale() before the rest of the request runs — the first real
 * consumer of the Site Settings mechanism
 * (site-settings-design.md). No caching: §8 is explicit that a single
 * indexed lookup by primary key is not a performance concern at this
 * scale, and caching should only be added if evidence says otherwise.
 *
 * Registered in BOTH bootstrap/app.php's global 'web' middleware group
 * AND AdminPanelProvider's own ->middleware([...]) array — confirmed
 * directly against the installed Filament v5.8.1 source
 * (vendor/filament/filament/routes/web.php) that these are two
 * genuinely separate pipelines: the panel's routes are registered with
 * Route::middleware($panel->getMiddleware()), never Laravel's global
 * 'web' string alias. A future storefront route (routes/web.php,
 * through the real 'web' group) would otherwise never see this
 * middleware at all.
 */
class ApplyStoreLocale
{
    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->settings->get('site.locale') ?? config('app.locale');

        App::setLocale($locale);

        return $next($request);
    }
}
