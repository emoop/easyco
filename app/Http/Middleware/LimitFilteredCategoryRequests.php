<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * CT-3: a category request WITH a filter is the one a crawler multiplies (every combination is a page), so it alone is limited: LIMIT per client IP per DECAY seconds, through the cache store (no session,
 * no cookie). A request without a `filter` parameter (a category, a product, the cart, the home page) never touches this limit. Over it: 429 with `Retry-After`, `noindex`, `no-store`, a plain page;
 * the first refusal of an IP in a minute is logged once (IP, how many, the path without its query: no value from the URL reaches the log).
 *
 * The IP is `$request->ip()` as the application's own TrustProxies configuration resolves it (config/trustedproxy.php); `X-Forwarded-For` is never read here. Behind Varnish or nginx the trusted
 * proxy list must be that proxy's address only (127.0.0.1), or every visitor shares one limit.
 */
final class LimitFilteredCategoryRequests
{
    /** Filtered requests per IP per DECAY seconds: the one place of the number. */
    public const LIMIT = 120;

    public const DECAY = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->query->has('filter')) {
            return $next($request);
        }

        $ip = (string) $request->ip();
        $key = 'filtered-category:'.$ip;

        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            // once per IP per minute
            if (Cache::add('filtered-category-logged:'.$ip, 1, self::DECAY)) {
                Log::warning('Filtered category requests over the limit', ['ip' => $ip, 'attempts' => RateLimiter::attempts($key), 'limit' => self::LIMIT, 'path' => '/'.ltrim($request->path(), '/')]);
            }

            return response('<!DOCTYPE html><html lang="'.str_replace('_', '-', app()->getLocale()).'"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>'.e(__('storefront.too_many_title')).'</title></head><body><p>'.e(__('storefront.too_many_text')).'</p></body></html>', 429, [
                'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
                'X-Robots-Tag' => 'noindex',
                'Cache-Control' => 'no-store, private',
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        RateLimiter::hit($key, self::DECAY);

        return $next($request);
    }
}
