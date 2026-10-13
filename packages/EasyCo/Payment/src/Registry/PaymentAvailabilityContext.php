<?php

namespace EasyCo\Payment\Registry;

/**
 * What a payment method may look at to say "not for this order" (checkout-form-v2-design.md section 8.2): the delivery country, the
 * order's currency, its total in minor units and the kind of delivery ('address' | 'office' | 'locker', or null when not known: the
 * checkout page asks BEFORE a delivery is chosen, the API asks with the real order). Plain values: this package imports nothing from
 * the other domains.
 */
final class PaymentAvailabilityContext
{
    public function __construct(
        public readonly ?string $country,
        public readonly string $currency,
        public readonly int $totalMinor,
        public readonly ?string $deliveryKind = null,
    ) {
    }
}
