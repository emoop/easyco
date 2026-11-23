<?php

namespace App\Storefront\Cache;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * P8b: decides, per response, whether the page cache may keep it, and writes `Cache-Control` and `xkey`.
 *
 * CACHEABLE = a GET/HEAD to one of the named storefront routes below, answered 200 or 301, with no `filter` and no `q` parameter and no explicit
 * `no-store` already set by the controller: `Cache-Control: public, max-age=0, s-maxage=604800` and `xkey: all <the page's keys>`.
 * EVERYTHING ELSE (a filtered listing, search, cart, checkout, previews, any other status — the 404 included — any other method, an unnamed route) is left
 * exactly as it is: it never gets an `xkey` and never `s-maxage`.
 *
 * SAFETY NET: a response that would be cacheable but carries a `Set-Cookie` is downgraded to `private, no-store` with no `xkey`, and logged (route name only).
 *
 * Symfony writes the directives in alphabetical order (`max-age=0, public, s-maxage=604800`): the same header, the same meaning.
 */
final class StorefrontCacheHeaders
{
    public const CACHE_CONTROL = 'public, max-age=0, s-maxage=604800';

    /** The route names of the storefront pages the page cache may keep. */
    public const ROUTES = [
        'storefront.home', 'storefront.product', 'storefront.category',
        'storefront.brand.index', 'storefront.brand', 'storefront.season', 'storefront.tag',
        'storefront.collection.index', 'storefront.collection', 'storefront.page',
        'seo.robots', 'seo.favicon', 'seo.sitemap', 'seo.sitemap.pages', 'seo.sitemap.categories', 'seo.sitemap.brands', 'seo.sitemap.seasons',
        'seo.sitemap.collections', 'seo.sitemap.tags', 'seo.sitemap.products',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->cacheable($request, $response)) {
            return $response;
        }

        if ($response->headers->getCookies() !== []) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->remove('xkey');
            Log::warning('A cacheable storefront response carried a cookie: not cached.', ['route' => (string) $request->route()?->getName()]);

            return $response;
        }

        $keys = app(SurrogateKeys::class);
        $keys->add('all');

        $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        $response->headers->set('xkey', implode(' ', $keys->all()));

        return $response;
    }

    private function cacheable(Request $request, Response $response): bool
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if (! in_array($response->getStatusCode(), [200, 301], true)) {
            return false;
        }

        if (! in_array((string) $request->route()?->getName(), self::ROUTES, true)) {
            return false;
        }

        if ($request->query->has('filter') || $request->query->has('q')) {
            return false;
        }

        // a controller that already said "never store" keeps its word
        return ! $response->headers->hasCacheControlDirective('no-store');
    }
}
