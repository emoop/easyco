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

    /**
     * The order's SETTLED payment, if it has one — the row the money is
     * held on: `status = captured OR confirmed_at IS NOT NULL`, and not
     * voided (order-lifecycle-design.md §7.3).
     *
     * It exists because findByOrderId()'s newest row is not the same
     * question: the most recently *attempted* payment may be a failed retry,
     * and refunding that would be refunding the wrong thing. A voided row is
     * excluded explicitly — a called-off obligation carries no money, even
     * though it stays in the order's payment trail.
     *
     * THE PREDICATE IS WRITTEN OUT HERE RATHER THAN READ FROM
     * settled_order_id, deliberately: the generated column and this
     * predicate are two independent expressions of the same rule, and the
     * unique index on the column is the actual guarantee (at most one row
     * can match). `Payment::isSettled()` is the same rule in the domain and
     * the two must agree — a test in tests/Feature proves they do on a mixed
     * set of rows, so a change to either expression cannot pass silently.
     */
    public function findSettledForOrder(string $orderId): ?Payment;
}
