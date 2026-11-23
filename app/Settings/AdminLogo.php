<?php

namespace App\Settings;

use App\Storefront\Appearance\StorefrontAppearance;
use Throwable;

/**
 * LOGO-1 (site-logo.md): what the admin panel prints as its favicon and logos, for the closures of AdminPanelProvider (`favicon()`, `brandLogo()`, `darkModeBrandLogo()`). They read the CACHED appearance
 * payload (no query of their own; on `CACHE_STORE=database` that read is ONE cheap primary-key lookup shared by all four closures, on Redis or the array store it is none) and fall back to TODAY'S
 * static files when nothing is set, the payload is damaged or anything at all goes wrong. The login page runs before auth: nothing here needs a user, and nothing can make it fail.
 */
final class AdminLogo
{
    public static function favicon(): string
    {
        return self::url(self::logo()['icon']['png32']['url'] ?? null) ?? asset('favicon.svg');
    }

    public static function logo(): array
    {
        try {
            return app(StorefrontAppearance::class)->siteLogo() ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function light(): string
    {
        return self::url(self::logo()['main']['png']['url'] ?? null) ?? asset('images/logo.svg');
    }

    /** The dark-mode logo: `logo.dark`, else `logo.main` (a dark logo needs the light one the tab asks for), else EasyCo's white file. */
    public static function dark(): string
    {
        $logo = self::logo();

        return self::url($logo['dark']['png']['url'] ?? null) ?? self::url($logo['main']['png']['url'] ?? null) ?? asset('images/easyco-logo-white.svg');
    }

    public static function name(): string
    {
        return ShopName::current();
    }

    private static function url(mixed $path): ?string
    {
        return is_string($path) && $path !== '' ? asset(ltrim($path, '/')) : null;
    }
}
