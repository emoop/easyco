<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACC-3c: every response of the signed-in account API (the profile, the orders, the address book) is personal: `Cache-Control: private,
 * no-store`, so no shared cache and no back/forward cache keeps it. Set after the controller, whatever it (or the session) wrote.
 */
final class AccountApiNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
