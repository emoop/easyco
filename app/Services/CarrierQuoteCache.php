<?php

namespace App\Services;

use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\CarrierCallResult;
use EasyCo\Shipping\Carrier\CarrierUnavailableReason;
use EasyCo\Shipping\Carrier\ShippingContext;
use EasyCo\Shipping\Carrier\ShippingQuote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The quote cache of shipping-domain-design.md §6.5, around CarrierCallGuard
 * (the guard itself stays cache-free).
 *
 *  - key: `shipping:quote:v1:<carrierCode>:<sha256 of the JSON of
 *    ShippingContext::toCanonicalArray()>` — equal contexts share a key, any
 *    difference is another one, and the key holds no customer identity;
 *  - lifetime: an answer (empty included) QuoteCachePolicy::ANSWER_TTL, an
 *    UNAVAILABLE result UNAVAILABLE_TTL;
 *  - a cache that is down never takes the quote down: a failed read or write is
 *    logged (class only) and the call simply goes to the carrier.
 *
 * What is stored are plain arrays, never objects: a cache entry must survive a
 * class change without an unserialize error.
 */
final class CarrierQuoteCache
{
    public const KEY_PREFIX = 'shipping:quote:v1:';

    public function __construct(private readonly CarrierCallGuard $guard)
    {
    }

    /** @return CarrierCallResult<ShippingQuote> */
    public function quotes(string $carrierCode, ShippingContext $context, CallBudget $budget): CarrierCallResult
    {
        $key = self::keyFor($carrierCode, $context);

        $cached = $this->read($key);

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->guard->quotes($carrierCode, $context, $budget);

        $this->write($key, $result);

        return $result;
    }

    public static function keyFor(string $carrierCode, ShippingContext $context): string
    {
        return self::KEY_PREFIX.$carrierCode.':'.hash('sha256', json_encode($context->toCanonicalArray(), JSON_THROW_ON_ERROR));
    }

    /** @return CarrierCallResult<ShippingQuote>|null */
    private function read(string $key): ?CarrierCallResult
    {
        try {
            $entry = Cache::get($key);
        } catch (Throwable $e) {
            Log::warning('Shipping quote cache read failed.', ['exception' => $e::class]);

            return null;
        }

        if (! is_array($entry) || ! array_key_exists('reason', $entry) || ! is_array($entry['quotes'] ?? null)) {
            return null;
        }

        try {
            if ($entry['reason'] !== null) {
                return CarrierCallResult::unavailable(CarrierUnavailableReason::from($entry['reason']));
            }

            return CarrierCallResult::answered(array_map(
                static fn (array $q): ShippingQuote => new ShippingQuote($q['service'], $q['name'], $q['amount'], $q['currency']),
                $entry['quotes'],
            ));
        } catch (Throwable) {
            return null; // a malformed entry is a miss, never an error
        }
    }

    /** @param CarrierCallResult<ShippingQuote> $result */
    private function write(string $key, CarrierCallResult $result): void
    {
        $entry = [
            'reason' => $result->reason()?->value,
            'quotes' => array_map(
                static fn (ShippingQuote $q): array => ['service' => $q->serviceCode, 'name' => $q->name, 'amount' => $q->amountMinor, 'currency' => $q->currency],
                $result->items(),
            ),
        ];

        try {
            Cache::put($key, $entry, $result->isAvailable() ? QuoteCachePolicy::ANSWER_TTL : QuoteCachePolicy::UNAVAILABLE_TTL);
        } catch (Throwable $e) {
            Log::warning('Shipping quote cache write failed.', ['exception' => $e::class]);
        }
    }
}
