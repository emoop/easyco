<?php

namespace EasyCo\Shipping\Carrier;

/**
 * One live rate a carrier offers for a ShippingContext (shipping-domain-design.md §6).
 *
 *  - serviceCode: the carrier's own identifier of the service ("office", "address",
 *    "locker"…), opaque to EasyCo; lets the checkout come back to the same service.
 *  - name: what the customer sees ("Econt to office").
 *  - amountMinor, currency: the price, non-negative (a free service is 0). The
 *    currency must be the context's currency; a quote in another currency is a
 *    provider fault that CarrierCallGuard turns into "unavailable".
 */
final class ShippingQuote
{
    public function __construct(
        public readonly string $serviceCode,
        public readonly string $name,
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {
        $class = self::class;

        CarrierValue::nonEmpty($class, 'serviceCode', $serviceCode, 64);
        CarrierValue::nonEmpty($class, 'name', $name);
        CarrierValue::nonNegative($class, 'amountMinor', $amountMinor);
        CarrierValue::currency($class, 'currency', $currency);
    }
}
