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
 *  - `privacy-confirm` (ACC-4d): POST /account/privacy/confirm, `privacy.confirm_per_minute` (10) per IP; the refusal is a no-store HTML 429.
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

        // ACC-4d: POST /account/privacy/confirm, per IP; the refusal is a small no-store HTML page (the route answers a browser form, not the API)
        RateLimiter::for('privacy-confirm', static fn (Request $request): Limit => Limit::perMinute((int) config('privacy.confirm_per_minute', 10))
            ->by('privacy-confirm|'.$request->ip())
            ->response(static fn (Request $request, array $headers) => response(
                '<!DOCTYPE html><html lang="'.str_replace('_', '-', app()->getLocale()).'"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>'.e(__('storefront.too_many_title')).'</title></head><body><p>'.e(__('storefront.too_many_text')).'</p></body></html>',
                429,
                $headers + ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex', 'Content-Type' => 'text/html; charset=UTF-8'],
            )));

        Route::pushMiddlewareToGroup('web', ThrottleLivewireUpdates::class);
    }
}
