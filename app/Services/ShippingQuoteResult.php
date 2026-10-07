<?php

namespace App\Services;

/**
 * The finished quote: the offered methods (each with a handle when it has a
 * price) and the facts they were priced on. Built by
 * ShippingQuoteService::issueHandles().
 */
final class ShippingQuoteResult
{
    /** @param list<MethodQuote> $methods */
    public function __construct(
        public readonly string $cartId,
        public readonly string $currency,
        public readonly int $goodsAfterDiscountMinor,
        public readonly string $zoneId,
        public readonly string $zoneName,
        public readonly string $pricingHash,
        public readonly array $methods,
        public readonly ?FreeShippingHint $freeShippingHint = null,
    ) {
    }

    public function method(string $methodId): ?MethodQuote
    {
        foreach ($this->methods as $method) {
            if ($method->methodId === $methodId) {
                return $method;
            }
        }

        return null;
    }
}
