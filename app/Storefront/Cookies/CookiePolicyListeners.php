<?php

namespace App\Storefront\Cookies;

use App\Content\Models\PageModel;
use App\Settings\CookieSettings;
use App\Storefront\Appearance\StorefrontAppearance;

/**
 * CC-1: keeps the banner's policy link TRUE. The link ({slug, title}) is derived from the picked page and lives in the appearance payload the storefront reads without a query; when THAT page is
 * renamed (slug or title), unpublished or deleted, the derived link is refreshed and the payload replaced (one purge), so a visitor never meets a dead link.
 *
 * Why not MenuRecompileListeners: it rebuilds only when a MENU holds a page (`holds('page')`), and the policy page need not be in any menu. This is the same Eloquent-event pattern, on its own.
 * A write that bypasses Eloquent fires no event (CookieSettings::refreshPolicyLink() / saving the cookie page repairs that).
 */
final class CookiePolicyListeners
{
    public static function register(): void
    {
        PageModel::saved(static function (PageModel $page): void {
            if ($page->wasChanged(['status', 'slug', 'title'])) {
                self::refreshIfPicked((int) $page->getKey());
            }
        });
        PageModel::deleted(static fn (PageModel $page) => self::refreshIfPicked((int) $page->getKey()));
    }

    private static function refreshIfPicked(int $pageId): void
    {
        $settings = app(CookieSettings::class);

        if ($settings->policyPageId() !== $pageId) {
            return;
        }

        $settings->refreshPolicyLink();
        app(StorefrontAppearance::class)->refresh();
    }
}
