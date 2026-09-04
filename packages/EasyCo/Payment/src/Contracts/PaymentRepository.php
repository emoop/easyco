<?php

namespace EasyCo\Payment\Contracts;

use EasyCo\Payment\Payment;

interface PaymentRepository
{
    public function save(Payment $payment): void;

    public function findById(string $id): ?Payment;

    /**
     * Every attempt for that order, across retries — a failed attempt
     * is never rewritten in place (payment-domain-design.md §1), so
     * this can return more than one row per orderId. Useful for a
     * future Checkout/support tooling to see the full attempt history.
     *
     * @return Payment[]
     */
    public function findByOrderId(string $orderId): array;
}
