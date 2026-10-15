<?php

namespace App\Storefront\Theme;

use App\Storefront\Appearance\StorefrontAppearance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the active theme's views FIRST in the view finder for this request (storefront-customization-design.md 2.2). Registered on the STOREFRONT route
 * group only, never on the admin, the API or the mail (they are not themed). The default theme `easyco` has no views folder of its own: it prepends
 * nothing, so it costs nothing. A theme that names a folder overrides views by file name; anything it lacks falls back to resources/views.
 */
final class ApplyStorefrontTheme
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly StorefrontAppearance $appearance,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $path = $this->themes->active($this->appearance->themeKey())->viewsPath();

        if ($path !== null) {
            $finder = app('view')->getFinder();

            if (! in_array($path, $finder->getPaths(), true)) {
                $finder->prependLocation($path);
            }
        }

        return $next($request);
    }
}
