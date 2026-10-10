<?php

namespace App\Storefront\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers of every storefront route. Deliberately NOTHING about caching here: HTTP caching (Cache-Control,
 * ETag, 304, purge) is stage S4; until then Laravel's default (no public caching) stands.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
