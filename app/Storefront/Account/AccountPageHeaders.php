<?php

namespace App\Storefront\Account;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACC-3a: the headers of every account page: `private, no-store` (never a shared cache) and `X-Robots-Tag: noindex`. With the parameter `no-referrer`
 * (the reset, verify and email-confirm pages: their url carries a token) also `Referrer-Policy: no-referrer`. List it BEFORE SecurityHeaders on the route:
 * the outer class writes last, so the policy stays (the same arrangement as OrderReceivedHeaders).
 */
final class AccountPageHeaders
{
    public function handle(Request $request, Closure $next, ?string $referrer = null): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        if ($referrer === 'no-referrer') {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
