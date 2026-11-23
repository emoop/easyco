<?php

namespace App\Settings;

use App\Storefront\Appearance\StorefrontAppearance;
use Throwable;

/**
 * LOGO-1 (site-logo.md): THE ONE RESOLVER of the shop's name. The merchant's `site.name` (the «Лого» tab), else `config('app.name')` (APP_NAME) exactly as before. It reads the cached appearance
 * payload (no query of its own), is safe in a queue worker, and NEVER fails: any problem is the APP_NAME. Every place that prints the shop name asks here (a test finds a raw `config('app.name')`).
 *
 * The sender's default name (`mail.from.<sender>.name`, MailConfigurator) and the admin-login alarm's subject are NOT resolved here on purpose.
 */
final class ShopName
{
    private static bool $resolving = false;

    public static function current(): string
    {
        // a validator or a default that is built WHILE the payload is being built asks too: it gets APP_NAME, never a loop
        if (self::$resolving) {
            return (string) config('app.name');
        }

        self::$resolving = true;

        try {
            return app(StorefrontAppearance::class)->shopName();
        } catch (Throwable) {
            return (string) config('app.name');
        } finally {
            self::$resolving = false;
        }
    }
}
