<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `EASYCO_NOINDEX=true` (config('easyco.noindex')): a test / staging site must never be indexed. Done in the app, not only in the web server, so it
 * holds whatever proxy sits in front. With the switch off this middleware changes nothing.
 */
final class NoIndexResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('easyco.noindex')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
