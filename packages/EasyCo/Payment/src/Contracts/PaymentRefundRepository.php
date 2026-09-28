<?php

namespace EasyCo\Payment\Contracts;

use EasyCo\Payment\PaymentRefund;
use EasyCo\Pricing\Money;

interface PaymentRefundRepository
{
    public function save(PaymentRefund $refund): void;

    public function findById(string $id): ?PaymentRefund;

    /** @return PaymentRefund[] */
    public function findByPaymentId(string $paymentId): array;

    /**
     * The total already refunded against one payment, in COMPLETED refunds
     * only (order-lifecycle-design.md §7.3, R8(a)) — the other half of
     * payment-domain-design.md §5.2's "a payment is never refunded beyond
     * what it captured".
     *
     * COMPUTED IN SQL, NEVER BY LOADING ROWS: it is a SUM over the
     * payment's own rows (payment_id is the leading key of that lookup), not
     * a collection to hydrate and add up — the same reason every other
     * total in this codebase is a query. A pending or failed refund counts
     * for nothing: money that has not moved back yet does not cap anything,
     * which is why the two sums §7.3 mentions (completed refunds here, the
     * order's own payments elsewhere) answer two different questions.
     *
     * $currency is the payment's own currency, and the returned Money is
     * zero in it when the payment has no completed refunds — a real answer,
     * never a null to be interpreted.
     */
    public function sumCompletedForPayment(string $paymentId, string $currency): Money;
}
