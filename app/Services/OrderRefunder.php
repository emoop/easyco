<?php

namespace App\Services;

use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * order-lifecycle-design.md §7.3 (its own stage 6b-ii, part 1) — the money
 * half of a cancellation or a return: one `PaymentRefund`, a void-and-
 * maybe-reissue, or nothing at all. R8's three cases, exactly.
 *
 * ASSUMES IT IS ALREADY INSIDE AN OPEN TRANSACTION — DOES NOT CALL
 * DB::transaction() ITSELF, AND FIRES NO HOOK. The same shape
 * OrderPaymentConfirmer::confirmWithinOpenTransaction() and
 * ReturnGoodsRecorder::record() already establish: a future
 * OrderStatusChanger will call this from inside its own locked
 * transaction, "under the payment's own row lock" per §7.3's own opening
 * sentence — this class does not take that lock itself, the same way
 * ReturnGoodsRecorder does not lock the order it restocks against.
 *
 * NOT WIRED IN YET. This pass builds OrderRefunder as an independent
 * collaborator only — OrderStatusChanger is untouched, per this stage's
 * own explicit instruction. refund() returns an OrderRefundOutcome so
 * that future caller can pick the right order_events type (REFUNDED /
 * PAYMENT_VOIDED / nothing, order-lifecycle-design.md §7.3's own table)
 * and hand the right PaymentRefund to the order.refunded hook, without a
 * second query to re-derive what just happened.
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
    ) {}

    /**
     * @throws InvalidArgumentException If the order's payments are in an
     *   anomalous state (more than one simultaneously settled, more than
     *   one simultaneously pending-answered-unvoided-unconfirmed, or one
     *   of each at once), if a settled-payment refund would exceed what
     *   remains refundable, or if a pending-payment refund would leave a
     *   negative remainder.
     */
    public function refund(string $orderId, Money $totalRefundAmount, DateTimeImmutable $occurredAt, ?string $reason): OrderRefundOutcome
    {
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
            return $this->refundSettled($orderId, $settled[0], $totalRefundAmount, $reason);
        }

        if (count($pendingEligible) === 1) {
            return $this->voidAndMaybeReissue($orderId, $pendingEligible[0], $totalRefundAmount, $occurredAt);
        }

        // (c) No payment at all, or every payment already voided/failed:
        // there is no recorded money to move — write nothing, not an
        // error (order-lifecycle-design.md §7.3, its own R8(c)/§14 Q3).
        return OrderRefundOutcome::nothingRecorded();
    }

    /**
     * R8(a): one PaymentRefund for the returned units' share, capped by
     * what the settled payment has not already had refunded.
     */
    private function refundSettled(string $orderId, Payment $settledPayment, Money $totalRefundAmount, ?string $reason): OrderRefundOutcome
    {
        $alreadyRefunded = $this->paymentRefunds->sumCompletedForPayment(
            $settledPayment->id(),
            $settledPayment->amount()->currency()->code(),
        );
        $remainingRefundable = $settledPayment->amount()->subtract($alreadyRefunded);

        if ($remainingRefundable->subtract($totalRefundAmount)->isNegative()) {
            throw new InvalidArgumentException(sprintf(
                'OrderRefunder: refunding %d minor unit(s) against payment "%s" would exceed its remaining refundable amount (%d minor unit(s) already refunded of %d captured).',
                $totalRefundAmount->minorValue(),
                $settledPayment->id(),
                $alreadyRefunded->minorValue(),
                $settledPayment->amount()->minorValue(),
            ));
        }

        $adapter = $this->adapterResolver->resolve($settledPayment->method());
        $attempt = $adapter->refund($settledPayment, $totalRefundAmount, new PaymentContext($orderId));

        $staff = $this->staffActor->current();

        $refund = PaymentRefund::create(
            paymentId: $settledPayment->id(),
            amount: $totalRefundAmount,
            status: $attempt->status(),
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
    private function voidAndMaybeReissue(string $orderId, Payment $pendingPayment, Money $totalRefundAmount, DateTimeImmutable $occurredAt): OrderRefundOutcome
    {
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
