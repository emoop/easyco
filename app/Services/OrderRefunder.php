<?php

namespace App\Services;

use App\Services\Exceptions\NonOfflineRefundAdapterException;
use App\Services\Exceptions\PendingPaymentRefundRuleException;
use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Payment\RefundBreakdown;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * order-lifecycle-design.md §7.3 (its own stage 6b-ii, part 1) — the money
 * half of a cancellation or a return: one `PaymentRefund`, a void-and-
 * maybe-reissue, or nothing at all. R8's three cases, exactly.
 *
 * ASSUMES IT IS ALREADY INSIDE AN OPEN TRANSACTION — DOES NOT CALL
 * DB::transaction() ITSELF, AND FIRES NO HOOK. OrderStatusChanger calls it from
 * inside its own transaction, with THE ORDER ROW LOCKED FIRST
 * (OrderRepository::findByIdForUpdate): that order lock is the single
 * serialization point for everything that moves an order's money
 * (shipping-domain-design.md §7.2.3). It is NOT a payment-row lock — an
 * earlier version of this comment said "under the payment's own row lock",
 * which was never true of the code; this class takes no lock itself, the same
 * way ReturnGoodsRecorder does not lock the order it restocks against.
 *
 * refund() returns an OrderRefundOutcome so the caller can pick the right
 * order_events type (REFUND_OWED / REFUNDED / PAYMENT_VOIDED / nothing,
 * order-lifecycle-design.md §7.3's own table) and hand the right PaymentRefund
 * to the order.refunded hook, without a second query to re-derive what just
 * happened.
 *
 * EVERY REFUND THIS CLASS WRITES IS OFFLINE (stage R1a): it asks the adapter
 * isOffline() first and refuses a method that is not, by name
 * (NonOfflineRefundAdapterException), because an online provider call must
 * never run inside the database transaction (§7.2.3) — that path is R5. The two
 * shipped adapters return OWED: the refund is decided and recorded, and the
 * merchant pays it back later.
 *
 * NO order_events ROW, NO HOOK, NO ORDER WRITE OF ANY KIND — all three
 * belong to the order context, which is OrderStatusChanger's job, not
 * this service's, exactly the boundary ReturnGoodsRecorder's own docblock
 * already draws for the goods half.
 */
final class OrderRefunder
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentRefundRepository $paymentRefunds,
        private readonly PaymentMethodAdapterResolver $adapterResolver,
        private readonly PanelStaffActor $staffActor,
        private readonly PendingPaymentReissuer $pendingPaymentReissuer,
        private readonly RefundCapGuard $capGuard,
        private readonly RefundPermissionPolicy $permissions,
    ) {}

    /**
     * @throws InvalidArgumentException If the order's payments are in an
     *   anomalous state (more than one simultaneously settled, more than
     *   one simultaneously pending-answered-unvoided-unconfirmed, or one
     *   of each at once), if a settled-payment refund would exceed what
     *   remains refundable, or if a pending-payment refund would leave a
     *   negative remainder.
     */
    public function refund(
        string $orderId,
        Money $totalRefundAmount,
        DateTimeImmutable $occurredAt,
        ?string $reason,
        ?RefundBreakdown $breakdown = null,
        ?RefundChannel $channel = null,
        bool $fullReturn = false,
        bool $goodsDifferFromComputed = false,
    ): OrderRefundOutcome {
        // The total is always goods + shipping + adjustment - deduction. With no
        // breakdown given it is all goods (what a plain amount has always meant).
        if ($breakdown !== null && ! $breakdown->total()->equals($totalRefundAmount)) {
            throw new InvalidArgumentException(sprintf(
                'OrderRefunder: the refund total (%d) does not equal its breakdown (%d).',
                $totalRefundAmount->minorValue(),
                $breakdown->total()->minorValue(),
            ));
        }

        $orderPayments = $this->payments->findByOrderId($orderId);

        $settled = array_values(array_filter(
            $orderPayments,
            static fn (Payment $payment): bool => $payment->isSettled(),
        ));

        $pendingEligible = array_values(array_filter(
            $orderPayments,
            static fn (Payment $payment): bool => $payment->status() === PaymentStatus::PENDING
                && $payment->attemptedAt() !== null
                && $payment->confirmedAt() === null
                && ! $payment->isVoided(),
        ));

        // "A real anomaly" (this stage's own wording): under normal
        // checkout/confirmation flow at most one payment is ever settled,
        // and OrderPaymentConfirmer's own courtesy check already refuses a
        // second confirmation once one is settled — so more than one of
        // either category, or one of EACH simultaneously, describes a
        // state this codebase's own writers should never produce. Refused
        // loudly rather than guessed at.
        if (count($settled) > 1) {
            throw new InvalidArgumentException(
                "OrderRefunder: order \"{$orderId}\" has more than one settled payment simultaneously — a real anomaly."
            );
        }

        if (count($pendingEligible) > 1) {
            throw new InvalidArgumentException(
                "OrderRefunder: order \"{$orderId}\" has more than one pending, answered, unvoided, unconfirmed payment simultaneously — a real anomaly."
            );
        }

        if (count($settled) === 1 && count($pendingEligible) === 1) {
            throw new InvalidArgumentException(
                "OrderRefunder: order \"{$orderId}\" has a settled payment and a separate pending payment simultaneously — a real anomaly."
            );
        }

        if (count($settled) === 1) {
            // A refund of nothing (a fully emptied order whose goods were all free) is no refund.
            if (! $totalRefundAmount->isPositive()) {
                return OrderRefundOutcome::nothingRecorded();
            }

            return $this->refundSettled($orderId, $settled[0], $totalRefundAmount, $reason, $breakdown, $channel);
        }

        if (count($pendingEligible) === 1) {
            return $this->voidAndMaybeReissue($orderId, $pendingEligible[0], $totalRefundAmount, $occurredAt, $breakdown, $fullReturn, $goodsDifferFromComputed);
        }

        // (c) No payment at all, or every payment already voided/failed:
        // there is no recorded money to move — write nothing, not an
        // error (order-lifecycle-design.md §7.3, its own R8(c)/§14 Q3).
        return OrderRefundOutcome::nothingRecorded();
    }

    /**
     * R8(a): one PaymentRefund for the returned units' share, capped by
     * what the settled payment has not already had refunded (every refund
     * whose money is spoken for counts — OWED included).
     */
    private function refundSettled(
        string $orderId,
        Payment $settledPayment,
        Money $totalRefundAmount,
        ?string $reason,
        ?RefundBreakdown $breakdown,
        ?RefundChannel $channel,
    ): OrderRefundOutcome {
        // Recording a payout on a SETTLED payment needs the permission of its payout
        // channel — enforced HERE, so bypassing the panel's visibility rules stops
        // at the service (shipping-domain-design.md §7.2.8). Before anything is read
        // or written.
        $effectiveChannel = $channel ?? RefundChannel::defaultForMethod($settledPayment->method());
        $this->permissions->assertMayRecord($effectiveChannel);

        // The hard caps (§7.2.2), under the order lock the caller already holds and
        // before any refund write: per line, shipping, total, deduction.
        $breakdown ??= RefundBreakdown::goodsOnly($totalRefundAmount);
        $this->capGuard->assertWithinCaps($orderId, $settledPayment, $totalRefundAmount, $breakdown);

        $adapter = $this->adapterResolver->resolve($settledPayment->method());

        if (! $adapter->isOffline()) {
            throw NonOfflineRefundAdapterException::forMethod($settledPayment->method());
        }

        $attempt = $adapter->refund($settledPayment, $totalRefundAmount, new PaymentContext($orderId));

        $staff = $this->staffActor->current();

        $refund = PaymentRefund::create(
            paymentId: $settledPayment->id(),
            orderId: $orderId,
            amount: $totalRefundAmount,
            status: $attempt->status(),
            channel: $effectiveChannel,
            breakdown: $breakdown,
            reason: $reason,
            refundedBy: $staff !== null ? (string) $staff->id : null,
            failureReason: $attempt->failureReason(),
        );

        $this->paymentRefunds->save($refund);

        return OrderRefundOutcome::refunded($refund);
    }

    /**
     * R8(b): the pending payment's own requirement shrank. Void it
     * always; reissue a new pending payment for a positive remainder
     * through the SAME adapter/method — mirroring
     * CheckoutOrchestrator::place()'s own charge()-then-
     * recordAttemptResult() sequence exactly, one implementation, no
     * duplicated rule. A remainder of zero voids and stops; a remainder
     * that would be negative is refused before anything is written.
     */
    private function voidAndMaybeReissue(
        string $orderId,
        Payment $pendingPayment,
        Money $totalRefundAmount,
        DateTimeImmutable $occurredAt,
        ?RefundBreakdown $breakdown,
        bool $fullReturn,
        bool $goodsDifferFromComputed,
    ): OrderRefundOutcome {
        $breakdown ??= RefundBreakdown::goodsOnly($totalRefundAmount);

        // Nothing was paid (§7.2.4): there is nothing to deduct from, and the goods
        // are the computed share of the returned units — read-only.
        if ($breakdown->deduction->isPositive()) {
            throw PendingPaymentRefundRuleException::deductionNotAllowed();
        }

        if ($goodsDifferFromComputed) {
            throw PendingPaymentRefundRuleException::goodsAmountIsReadOnly();
        }

        // A FULL cancel or return leaves nothing owed: the payment is voided and
        // NEVER reissued (closes H1 — it used to reissue what was left, the
        // shipping, for an order that no longer exists).
        if ($fullReturn) {
            $this->pendingPaymentReissuer->voidOnly($pendingPayment, $occurredAt);

            return OrderRefundOutcome::voidedOnly($pendingPayment);
        }

        // A PARTIAL return reissues pending - goods - shipping reduction; the
        // shipping reduction (default 0) is capped at the shipping not yet reduced.
        $this->capGuard->assertPendingShippingReduction($orderId, $pendingPayment, $breakdown->goods, $breakdown->shipping);

        $remainder = $pendingPayment->amount()->subtract($totalRefundAmount);

        if ($remainder->isNegative()) {
            throw new InvalidArgumentException(sprintf(
                'OrderRefunder: refunding %d minor unit(s) against pending payment "%s" (amount %d minor unit(s)) would leave a negative remainder.',
                $totalRefundAmount->minorValue(),
                $pendingPayment->id(),
                $pendingPayment->amount()->minorValue(),
            ));
        }

        if ($remainder->isZero()) {
            $this->pendingPaymentReissuer->voidOnly($pendingPayment, $occurredAt);

            return OrderRefundOutcome::voidedOnly($pendingPayment);
        }

        // The void-and-reissue mechanics live in PendingPaymentReissuer
        // (order-editing stage 3b) — shared with order editing, which needs
        // the same sequence for a TARGET that may be higher as well as lower.
        $reissued = $this->pendingPaymentReissuer->reissueFor($pendingPayment, $remainder, $occurredAt);

        return OrderRefundOutcome::voidedAndReissued($pendingPayment, $reissued);
    }
}
