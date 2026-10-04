<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The quote handle (shipping-domain-design.md §6.7): an opaque token for ONE
 * offered price, so checkout (stage 4) can refuse a price the customer was never
 * shown instead of trusting a number from the client.
 *
 * A handle is stored in the cache for QuoteCachePolicy::HANDLE_TTL, bound to the
 * cart id, the method id, the amount and currency, and a hash of everything that
 * priced it (ShippingQuoteService::pricingHashFor()). verify() accepts a handle
 * ONLY if it exists, has not expired, and every one of those matches what the
 * caller presents now. Anything else — an unknown token, an expired one, another
 * cart, another method, a different hash, a different amount or currency, a cache
 * that cannot be read — is simply false: a handle is evidence, never a thing that
 * can error its way into passing.
 *
 * The cache key is a hash of the token, so the secret itself is never a key.
 * Expiry is checked twice: by the cache's own lifetime and against the expiry
 * stored in the entry (so a store that evicts late cannot extend a handle).
 * verify() does not consume the handle: one offered price may be verified by a
 * retried checkout; whether placement consumes it is stage 4's decision.
 */
final class QuoteHandleStore
{
    public const KEY_PREFIX = 'shipping:quote-handle:v1:';

    public function issue(string $cartId, string $methodId, int $amountMinor, string $currency, string $pricingHash): string
    {
        $handle = 'qh_'.Str::random(40);

        Cache::put(self::key($handle), [
            'cart' => $cartId,
            'method' => $methodId,
            'amount' => $amountMinor,
            'currency' => $currency,
            'hash' => $pricingHash,
            'expires_at' => Carbon::now()->getTimestamp() + QuoteCachePolicy::HANDLE_TTL,
        ], QuoteCachePolicy::HANDLE_TTL);

        return $handle;
    }

    public function verify(string $handle, string $cartId, string $methodId, int $amountMinor, string $currency, string $pricingHash): bool
    {
        try {
            $entry = Cache::get(self::key($handle));
        } catch (Throwable) {
            return false;
        }

        if (! is_array($entry)) {
            return false;
        }

        if (($entry['expires_at'] ?? 0) <= Carbon::now()->getTimestamp()) {
            return false;
        }

        return ($entry['cart'] ?? null) === $cartId
            && ($entry['method'] ?? null) === $methodId
            && ($entry['amount'] ?? null) === $amountMinor
            && ($entry['currency'] ?? null) === $currency
            && is_string($entry['hash'] ?? null)
            && hash_equals($entry['hash'], $pricingHash);
    }

    private static function key(string $handle): string
    {
        return self::KEY_PREFIX.hash('sha256', $handle);
    }
}
