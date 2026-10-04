<?php

namespace App\Http;

use App\Settings\StoreLocale;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * THE ONE PLACE the public API's named rate limiters are defined (shipping stage
 * 3d part 2). This is the convention the checkout endpoints will reuse: define a
 * named limiter here, attach it to the route with `throttle:<name>`, set its budget
 * in config/ratelimits.php. (Before this there was only the login route's inline
 * `throttle:6,1`.)
 *
 * THE CONVENTION:
 *  - the key is "who is asking, about what": the client IP, plus the identity of
 *    the cart being worked on (the signed-in customer's id, else the guest session's
 *    cart token, else none) — so one shopper cannot exhaust everyone behind the same
 *    IP, and one IP cannot hammer many carts under one limit;
 *  - the key never contains the cart token itself (it is a secret) but a hash of it;
 *  - the refusal is a 429 `{message, reason: "too_many_requests"}`, the message
 *    translated in the STORE locale (the `api` group never applies it), and the
 *    standard Retry-After / X-RateLimit headers.
 */
final class ApiRateLimits
{
    public const SHIPPING_QUOTE = 'shipping-quote';

    public const DEFAULT_PER_MINUTE = 30;

    public static function register(): void
    {
        RateLimiter::for(self::SHIPPING_QUOTE, fn (Request $request): Limit => self::limit($request, self::SHIPPING_QUOTE));
    }

    private static function limit(Request $request, string $name): Limit
    {
        $perMinute = (int) config('ratelimits.'.$name, self::DEFAULT_PER_MINUTE);
        $perMinute = $perMinute > 0 ? $perMinute : self::DEFAULT_PER_MINUTE;

        return Limit::perMinute($perMinute)
            ->by($name.'|'.$request->ip().'|'.self::cartIdentity($request))
            ->response(static function (Request $request, array $headers) {
                return response()->json([
                    'message' => __('shipping_quote.too_many_requests', [], app(StoreLocale::class)->current()),
                    'reason' => 'too_many_requests',
                ], 429, $headers);
            });
    }

    private static function cartIdentity(Request $request): string
    {
        if (Auth::guard('customer')->check()) {
            return 'customer:'.Auth::guard('customer')->id();
        }

        $token = $request->hasSession() ? $request->session()->get('cart_token') : null;

        return $token !== null ? 'guest:'.hash('sha256', (string) $token) : 'none';
    }
}
