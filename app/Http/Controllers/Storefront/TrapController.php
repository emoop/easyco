<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Support\NotFoundResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * P8d: the trap URL. Never linked visibly; `Disallow`ed in robots.txt, so only a crawler that ignores it (or hunts for hidden paths) arrives. A hit is LOGGED (IP, user
 * agent truncated, path) and answered with the storefront's own 404, which is never cacheable (the route is not in StorefrontCacheHeaders::ROUTES). The app keeps no
 * ban list: CrowdSec reads the access log (P8c).
 */
final class TrapController extends Controller
{
    public const UA_MAX = 120;

    public function show(Request $request): Response
    {
        Log::warning('Trap URL hit.', [
            'ip' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, self::UA_MAX),
            'path' => '/'.ltrim($request->path(), '/'),
        ]);

        return NotFoundResponse::make();
    }
}
