<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A site password for a test / staging deployment: HTTP basic auth in front of EVERYTHING, active only when both
 * `EASYCO_BASIC_AUTH_USER` and `EASYCO_BASIC_AUTH_PASSWORD` are set. `/up` (the health check) stays open; the admin keeps its own login behind it.
 *
 * IN THE APP, NOT IN NGINX: no htpasswd file to generate, nothing extra in the image, the same behaviour behind any proxy, and testable.
 * What it does not cover: files nginx serves directly (public/build assets, stored images) never reach PHP; they hold no private data.
 */
final class SiteBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('easyco.basic_auth.user');
        $password = (string) config('easyco.basic_auth.password');

        if ($user === '' || $password === '' || $request->is('up')) {
            return $next($request);
        }

        // Both comparisons always run (no early exit on the user name), constant-time.
        $userOk = hash_equals($user, (string) $request->getUser());
        $passwordOk = hash_equals($password, (string) $request->getPassword());

        if ($userOk && $passwordOk) {
            return $next($request);
        }

        return response('Authentication required.', 401, [
            'WWW-Authenticate' => 'Basic realm="Restricted", charset="UTF-8"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
