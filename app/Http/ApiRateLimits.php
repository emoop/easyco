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
 *    translated in the STORE locale (the framework's own locale never applies it),
 *    and the standard Retry-After / X-RateLimit headers.
 *
 * THE FIVE LIMITERS (the third input-hardening pass added four):
 *  - shipping-quote — POST /api/shipping/quote (the original one);
 *  - registration   — POST /api/account/register;
 *  - checkout       — POST /api/checkout;
 *  - address        — POST /api/addresses, PUT /api/addresses/{addressId};
 *  - promotion      — PUT /api/cart/promotion.
 * Their budgets are rows in config/ratelimits.php — one row each, asserted by a test.
 *
 * TWO KEY SHAPES, DELIBERATELY — IP + cart identity, or the IP ALONE:
 * registration and promotion are keyed by the IP alone, and NOT because their
 * endpoints happen to be cart-less (registration is, promotion is not): in both, the
 * caller gets to CHOOSE the identity half of an IP + cart key — a fresh session
 * cookie hands them a fresh budget — which is right for a limiter counting work on a
 * cart (a rotated cart is a genuinely different cart, and shipping-quote's budget is
 * per cart by design) but worthless against the two attacks these two exist for.
 * Registration is account spam; applying a promo code is a SECRET being guessed.
 * Neither may be resettable at the price of a cookie, so both pay the convention's
 * own documented price instead: a whole IP shares one budget — which is exactly what
 * an abuser sitting behind a NAT should be sharing — and the numbers (5 and 10 a
 * minute) leave a human, who does each of these once, untouched.
 * checkout and address keep the IP + cart identity shape: their work is per cart, so
 * the convention's fairness argument applies to them unchanged.
 */
final class ApiRateLimits
{
    public const SHIPPING_QUOTE = 'shipping-quote';

    public const REGISTRATION = 'registration';

    public const CHECKOUT = 'checkout';

    public const ADDRESS = 'address';

    public const PROMOTION = 'promotion';

    public const DEFAULT_PER_MINUTE = 30;

    /**
     * Each limiter's refusal message, keyed by the limiter's own name — and the list
     * of limiters itself: register() walks this map, so a limiter without a message
     * cannot exist and a message without a limiter cannot either. The value is a lang
     * key, translated in the store locale (lang/{en,bg}/rate_limit.php, or the
     * shipping quote's own group); the four public write endpoints share one sentence
     * on purpose — see that file's own comment.
     */
    private const REFUSALS = [
        self::SHIPPING_QUOTE => 'shipping_quote.too_many_requests',
        self::REGISTRATION => 'rate_limit.too_many_requests',
        self::CHECKOUT => 'rate_limit.too_many_requests',
        self::ADDRESS => 'rate_limit.too_many_requests',
        self::PROMOTION => 'rate_limit.too_many_requests',
    ];

    /** The limiters keyed by the IP alone — see this class's own docblock, "TWO KEY SHAPES". */
    private const KEYED_BY_IP_ALONE = [self::REGISTRATION, self::PROMOTION];

    public static function register(): void
    {
        foreach (array_keys(self::REFUSALS) as $name) {
            RateLimiter::for($name, fn (Request $request): Limit => self::limit($request, $name));
        }
    }

    private static function limit(Request $request, string $name): Limit
    {
        $perMinute = (int) config('ratelimits.'.$name, self::DEFAULT_PER_MINUTE);
        $perMinute = $perMinute > 0 ? $perMinute : self::DEFAULT_PER_MINUTE;

        return Limit::perMinute($perMinute)
            ->by(self::key($request, $name))
            ->response(static function (Request $request, array $headers) use ($name) {
                return response()->json([
                    'message' => __(self::REFUSALS[$name], [], app(StoreLocale::class)->current()),
                    'reason' => 'too_many_requests',
                ], 429, $headers);
            });
    }

    private static function key(Request $request, string $name): string
    {
        if (in_array($name, self::KEYED_BY_IP_ALONE, true)) {
            return $name.'|'.$request->ip();
        }

        return $name.'|'.$request->ip().'|'.self::cartIdentity($request);
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
