<?php

namespace EasyCo\Payment;

/**
 * Plain, immutable data passed to a PaymentMethodAdapter alongside the
 * amount being charged/refunded — NOT an entity: no id, no assignId(),
 * no persistence. See payment-domain-design.md §4.
 *
 * Deliberately minimal for V1 (orderId only) — a real provider adapter
 * will eventually need more (customer email, billing details, etc.),
 * but nothing in this domain uses those yet, so they aren't built
 * speculatively. A plain constructor-promoted readonly-properties class
 * is enough to extend later; no builder needed.
 */
final class PaymentContext
{
    public function __construct(
        public readonly string $orderId,
    ) {
    }
}
