<?php

namespace App\Storefront\Cache;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * P8b: the Laravel-side caches (sitemaps, the 404 content) follow the same events as the page cache through a VERSION key that the collector bumps at
 * flush when `sitemap` / `all` is among the purged keys; their cache keys include the version, so a bump is an instant "forget".
 */
final class CacheVersion
{
    public const SITEMAP = 'sitemap';

    public const ALL = 'all';

    public static function get(string $name): int
    {
        try {
            return max(1, (int) Cache::get('storefront.cache.version.'.$name, 1));
        } catch (Throwable) {
            return 1;
        }
    }

    public static function bump(string $name): void
    {
        try {
            Cache::forever('storefront.cache.version.'.$name, self::get($name) + 1);
        } catch (Throwable) {
            // a cache store that is down cannot serve stale pages either
        }
    }
}
