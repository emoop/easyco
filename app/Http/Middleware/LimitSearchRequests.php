<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * SRCH-1: the search page is the one a bot multiplies (every query is a database search), so a request that carries a `q` is limited per client IP: `storefront.search.limit` (30) per
 * `storefront.search.decay_seconds` (60), through the cache store (no session, no cookie). Same style as LimitFilteredCategoryRequests: over the limit = 429 with `Retry-After`, `noindex`, `no-store`
 * and a plain page, the first refusal of an IP in a window logged once (IP and counts, never the query). The IP is `$request->ip()` as TrustProxies resolves it. P8 reviews the numbers.
 */
final class LimitSearchRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->query->has('q')) {
            return $next($request);
        }

        $limit = max(1, (int) config('storefront.search.limit', 30));
        $decay = max(1, (int) config('storefront.search.decay_seconds', 60));
        $ip = (string) $request->ip();
        $key = 'storefront-search:'.$ip;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            if (Cache::add('storefront-search-logged:'.$ip, 1, $decay)) {
                Log::warning('Search requests over the limit', ['ip' => $ip, 'attempts' => RateLimiter::attempts($key), 'limit' => $limit]);
            }

            return response('<!DOCTYPE html><html lang="'.str_replace('_', '-', app()->getLocale()).'"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>'.e(__('storefront.too_many_title')).'</title></head><body><p>'.e(__('storefront.too_many_text')).'</p></body></html>', 429, [
                'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
                'X-Robots-Tag' => 'noindex',
                'Cache-Control' => 'no-store, private',
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        RateLimiter::hit($key, $decay);

        return $next($request);
    }
}
