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
     * The total already refunded against one payment, counting every refund
     * whose money is spoken for — OWED, PAID_OUT, REQUESTED and COMPLETED
     * (PaymentRefundStatus::counting(); shipping-domain-design.md §7.2.2).
     * A CANCELLED or FAILED refund moved no money and counts for nothing.
     * The other half of payment-domain-design.md §5.2's "a payment is never
     * refunded beyond what it captured".
     *
     * (Until the owed/paid-out model an offline refund was COMPLETED the moment
     * it was made, and only COMPLETED counted; now an offline refund is OWED, so
     * the counting set had to widen with it or the cap would silently stop
     * counting every refund made.)
     *
     * COMPUTED IN SQL, NEVER BY LOADING ROWS: it is a SUM over the
     * payment's own rows (payment_id is the leading key of that lookup), not
     * a collection to hydrate and add up — the same reason every other
     * total in this codebase is a query.
     *
     * $currency is the payment's own currency, and the returned Money is
     * zero in it when the payment has no counting refunds — a real answer,
     * never a null to be interpreted.
     */
    public function sumCountingForPayment(string $paymentId, string $currency): Money;

    /**
     * The goods already refunded per ORIGINAL sale line, by refunds that count
     * (the same counting states), as minor units keyed by sale line id — the
     * per-line cap's "refunded so far" (shipping-domain-design.md §7.2.2). One
     * grouped SQL statement; a line with nothing refunded is simply absent from
     * the map. A CANCELLED or FAILED refund's rows count for nothing, which is
     * how cancelling a refund frees exactly its own room.
     *
     * @param  list<string>  $saleLineIds
     * @return array<string, int>
     */
    public function sumCountingLineAmounts(array $saleLineIds): array;

    /**
     * The shipping already refunded on an ORDER, by refunds that count, in minor
     * units — the shipping cap's "refunded so far".
     */
    public function sumCountingShippingForOrder(string $orderId): int;
}
