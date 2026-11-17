<?php

namespace App\Http\Middleware;

use Closure;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACC-1: after `auth:customer` (STRICT, the default). ACC-2: `EnsureAccountSessionIsCurrent::class.':soft'` on every route outside `auth:customer` that reads the customer guard: a stale
 * session is dropped and the request CONTINUES AS A GUEST (no 401).
 *
 * Strict, as in ACC-1: A session whose stored version differs from `accounts.session_version` (a password reset / change or an email change happened
 * elsewhere) is logged out and answered 401, exactly like a request that was never logged in. A customer restored from the REMEMBER cookie has no session value
 * yet; see the comment in handle().
 */
final class EnsureAccountSessionIsCurrent
{
    public function handle(Request $request, Closure $next, string $mode = 'strict'): Response
    {
        $guard = Auth::guard('customer');
        $account = $guard->user();

        // the `customer` guard is a session guard: a request with no session (a non-stateful API call) has no session version to compare
        if ($account instanceof AccountModel && $request->hasSession()) {
            $session = $request->session();

            if (! AccountSessionVersion::isCurrent($account, $session)) {
                // no value yet: a remember-cookie login (its token is rotated by every bump, so it is current), or a session older than this feature on an
                // account that was never bumped (version 0: nothing has invalidated it). Both adopt the account's version; once a bump has happened a session
                // without a value is dead.
                if (! $session->has(AccountSessionVersion::SESSION_KEY) && ($guard->viaRemember() || (int) $account->session_version === 0)) {
                    AccountSessionVersion::adopt($account, $session);
                } elseif ($mode === 'soft') {
                    // ACC-2: a route outside `auth:customer` (cart, checkout, the quote...): the stale customer is dropped for THIS request and the session is kept
                    // otherwise (a guest cart token in it stays); the request goes on as a guest. Not guard->logout(): that would rotate the remember token again and
                    // kill the remember cookie of the session that is still current.
                    $session->remove($guard->getName());
                    $session->forget(AccountSessionVersion::SESSION_KEY);
                    $guard->forgetUser();
                } else {
                    $guard->logout();
                    $session->invalidate();
                    $session->regenerateToken();

                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }
            }
        }

        return $next($request);
    }
}
