<?php

namespace App\Services;

use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use Illuminate\Support\Facades\Cache;

/**
 * P7-2: the ONE number behind the card badge «Безплатна доставка»: the LOWEST free-shipping threshold (`free_above_minor`) of the active local methods of the store country's broad zone (StoreBroadZoneMethods: the very zone the
 * cart's hint talks about; a CARRIER method and a method whose threshold applies to no kind are skipped, exactly as the hint's reader skips them), in the shop's default currency.
 *
 *  - NO threshold (null) when there is none, no zone, no store country, no default currency, or when the zone has an ACTIVE method with the price FREE: shipping is free for everyone, the badge would say nothing.
 *  - The value is CACHED WITHOUT EXPIRY (`Cache::forever`) and refreshed when a shipping method, a zone or the store country / currency is saved (the `shipping.*` hooks and the locale settings call `refresh()`), so a page
 *    reads it with no query. The cache is read only by a page that has the badge switched on; a cold cache (nothing stored yet, or `cache:clear`) computes it once and stores it.
 *
 * Never throws: whatever goes wrong is «no threshold».
 */
final class FreeShippingThreshold
{
    public const KEY = 'storefront.free_shipping_threshold';

    public function __construct(private readonly StoreBroadZoneMethods $broadZone)
    {
    }

    /** @return array{minor: int, currency: string}|null */
    public function current(): ?array
    {
        $stored = Cache::get(self::KEY);

        return is_array($stored) && array_key_exists('minor', $stored) ? self::shape($stored) : $this->refresh();
    }

    /** Recomputes the threshold from the shipping data and stores it. @return array{minor: int, currency: string}|null */
    public function refresh(): ?array
    {
        try {
            $value = $this->compute();
        } catch (\Throwable) {
            $value = null;
        }

        Cache::forever(self::KEY, ['minor' => $value['minor'] ?? null, 'currency' => $value['currency'] ?? null]);

        return $value;
    }

    /** @return array{minor: int, currency: string}|null */
    private function compute(): ?array
    {
        if (! DefaultCurrency::isConfigured()) {
            return null;
        }

        $lowest = null;

        foreach ($this->broadZone->methods() as $method) {
            if ($method->kind() === ShippingMethodKind::FREE) {
                return null;
            }

            if ($method->kind() === ShippingMethodKind::CARRIER || $method->freeAboveMinor() === null || $method->effectiveFreeDestinations() === []) {
                continue;
            }

            $lowest = $lowest === null ? $method->freeAboveMinor() : min($lowest, $method->freeAboveMinor());
        }

        return $lowest === null || $lowest < 1 ? null : ['minor' => $lowest, 'currency' => DefaultCurrency::get()->code()];
    }

    /** @param array<string, mixed> $stored @return array{minor: int, currency: string}|null */
    private static function shape(array $stored): ?array
    {
        return is_int($stored['minor'] ?? null) && is_string($stored['currency'] ?? null) ? ['minor' => $stored['minor'], 'currency' => $stored['currency']] : null;
    }
}
