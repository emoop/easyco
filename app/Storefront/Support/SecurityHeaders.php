<?php

namespace App\Storefront\Support;

use App\Security\Csp\CspSources;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers of every storefront route. Nothing here is per visitor, so they are safe on a cached page; caching itself (Cache-Control, xkey) is StorefrontCacheHeaders (P8b).
 *
 * P8d: `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy` (no camera, microphone, geolocation, payment, usb) and `Content-Security-Policy-Report-Only` built from the
 * closed CspSources registry (violations go to POST /csp-report). HSTS is set at Traefik (P8c), not here. The admin (Filament) gets none of this.
 */
final class SecurityHeaders
{
    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $response->headers->set('Content-Security-Policy-Report-Only', app(CspSources::class)->policy());

        return $response;
    }
}
