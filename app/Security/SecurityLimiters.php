<?php

namespace App\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * P8d: the named limiters of this package and where the Livewire one is attached.
 *
 *  - `livewire`: Livewire UPDATE requests (every admin click), keyed by the authenticated staff id (the IP when nobody is logged in), 300 per minute. Uploads keep their own
 *    `throttle:60,1`. The limiter is attached by a middleware in the `web` group (ThrottleLivewireUpdates) that acts only on the update route: Livewire registers that route
 *    itself, during ITS boot, and a second «custom» update route on the same path would never be matched, so the stock route is left alone and the group does the job (it
 *    also works with a cached route table).
 *  - `csp-report`: POST /csp-report, 30 per minute per IP.
 */
final class SecurityLimiters
{
    public static function register(): void
    {
        RateLimiter::for('livewire', static function (Request $request): Limit {
            $staff = Auth::guard('staff')->id();

            return Limit::perMinute((int) config('security.livewire_per_minute', 300))->by($staff !== null ? 'staff:'.$staff : 'ip:'.$request->ip());
        });

        RateLimiter::for('csp-report', static fn (Request $request): Limit => Limit::perMinute((int) config('security.csp_report.per_minute', 30))->by('ip:'.$request->ip()));

        Route::pushMiddlewareToGroup('web', ThrottleLivewireUpdates::class);
    }
}
