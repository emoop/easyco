<?php

namespace App\Sandbox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts `X-Robots-Tag: noindex` on every sandbox response — Prompt D, D7.
 *
 * WHY A MIDDLEWARE AND NOT A PER-CONTROLLER HEADER: the sandbox exposes
 * REAL merchant data (real product names, real prices, real stock) at a
 * guessable URL. "Every response sends noindex" has to keep holding when
 * a future stage adds a page or a route to this group and its author
 * forgets a header call. One middleware on the group cannot be forgotten
 * by a new controller.
 *
 * WHAT IT DOES NOT COVER, STATED SO IT IS NOT ASSUMED: a Throwable
 * escaping the pipeline (a genuine 500) is rendered by Laravel's
 * exception handler AFTER this middleware's own post-processing step
 * never runs, so a 500 response does not carry this header. Nothing in
 * the sandbox's read path is expected to throw (D8/fail-soft), and a 500
 * is not indexable content in practice — but the gap is real and
 * documented rather than left implicit. The sandbox's own 404 (a
 * deliberately NOT-listed product, D3) is NOT in that gap: the controller
 * returns a rendered 404 response normally instead of throwing, so the
 * header is applied to it like any other response — see
 * SandboxProductController::show() and its own docblock.
 *
 * A <meta name="robots" content="noindex"> is ALSO present in the
 * sandbox layout (D7's second, independent requirement — belt and
 * braces, since a crawler that ignores one may honour the other). This
 * middleware deliberately does not inject that meta into response
 * bodies: rewriting HTML at the middleware layer would mean string
 * manipulation of the response body for a tag the view can render itself.
 */
final class NoIndexHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
