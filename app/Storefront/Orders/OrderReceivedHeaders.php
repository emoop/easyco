<?php

namespace App\Storefront\Orders;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers of the order-received page: the storefront's usual ones (SecurityHeaders) EXCEPT the referrer policy, which is
 * `no-referrer` here: the signed url is the guest's only key to the order and must never reach another site in a Referer.
 * SecurityHeaders cannot be stacked under this (it would set its own policy last), so this route group uses this class
 * instead of it. Also `private, no-store` and noindex: the page is personal, never cacheable, never indexed.
 */
final class OrderReceivedHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
