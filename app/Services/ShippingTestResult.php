<?php

namespace App\Services;

/**
 * What "Try it" (shipping-domain-design.md §12.3.5) returns: the destination AS
 * THE MATCHER SEES IT (normalised settlement and postcode), the matched zone or
 * the refusal, every ACTIVE method of that zone with its real price and threshold
 * facts, and the free-shipping hint. Read-only: no handle is issued and nothing is
 * written. A refused destination has `refusalReason` set and no zone and no methods.
 */
final class ShippingTestResult
{
    /** @param list<ShippingTestMethod> $methods */
    public function __construct(
        public readonly string $countryCode,
        public readonly string $settlement,
        public readonly string $postcode,
        public readonly bool $isPickupPoint,
        public readonly int $goodsAfterDiscountMinor,
        public readonly string $currency,
        public readonly ?string $zoneId,
        public readonly ?string $zoneName,
        public readonly ?string $refusalReason,
        public readonly array $methods,
        public readonly ?FreeShippingHint $hint,
    ) {
    }

    public function isMatched(): bool
    {
        return $this->zoneId !== null;
    }
}
