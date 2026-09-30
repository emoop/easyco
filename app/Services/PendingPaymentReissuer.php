<?php

namespace App\Services;

use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentContext;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * The ONE implementation of "void this order's pending payment and put a
 * new pending payment for a TARGET amount in its place" — extracted from
 * OrderRefunder::voidAndMaybeReissue() (order-lifecycle-design.md R8(b)) in
 * stage 3b so that order EDITING (order-editing-design.md §6) shares it.
 *
 * WHY IT TAKES A TARGET, NOT A REDUCTION: R8(b) is a reduction (a return can
 * only lower what is owed, so OrderRefunder computes old − refund and would
 * refuse a negative remainder). An edit's recomputed total may be HIGHER as
 * well as lower, so the shared mechanics take the new amount directly;
 * OrderRefunder computes its own remainder and passes it as the target,
 * keeping its own negative-remainder refusal where it always was.
 *
 * MECHANICS, unchanged from OrderRefunder: void the old payment and save it;
 * resolve the adapter for the SAME method; charge() the target; create a new
 * PENDING payment and record the adapter's real answer with attemptedAt =
 * $occurredAt (never left null, never synthesized) — the same
 * charge()-then-recordAttemptResult() sequence CheckoutOrchestrator::place()
 * establishes. Only one payment is ever un-voided/current afterwards.
 *
 * ASSUMES IT IS ALREADY INSIDE AN OPEN TRANSACTION, under the caller's lock,
 * and fires no hook — the same shape as OrderRefunder itself.
 */
final class PendingPaymentReissuer
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentMethodAdapterResolver $adapterResolver,
    ) {}

    /**
     * The order's single pending, ANSWERED (attemptedAt set), unconfirmed,
     * un-voided payment — the only kind void-and-reissue applies to — or
     * null when there is none. The same filter OrderRefunder::refund() uses.
     *
     * @param  array<int, Payment>  $orderPayments  PaymentRepository::findByOrderId()'s result.
     *
     * @throws InvalidArgumentException If more than one qualifies — an anomaly this codebase's own writers never produce, refused loudly rather than guessed at.
     */
    public function currentPending(array $orderPayments, string $orderId): ?Payment
    {
        $eligible = array_values(array_filter(
            $orderPayments,
            static fn (Payment $payment): bool => $payment->status() === PaymentStatus::PENDING
                && $payment->attemptedAt() !== null
                && $payment->confirmedAt() === null
                && ! $payment->isVoided(),
        ));

        if (count($eligible) > 1) {
            throw new InvalidArgumentException(
                "PendingPaymentReissuer: order \"{$orderId}\" has more than one pending, answered, unvoided, unconfirmed payment simultaneously — a real anomaly."
            );
        }

        return $eligible[0] ?? null;
    }

    /** Voids $pending and stops — a target of zero owes nothing, so no replacement exists. */
    public function voidOnly(Payment $pending, DateTimeImmutable $occurredAt): void
    {
        $pending->void($occurredAt);
        $this->payments->save($pending);
    }

    /**
     * Voids $pending and returns the new pending payment for $newAmount
     * (which must be positive), charged through the same adapter/method.
     */
    public function reissueFor(Payment $pending, Money $newAmount, DateTimeImmutable $occurredAt): Payment
    {
        if (! $newAmount->isPositive()) {
            throw new InvalidArgumentException(
                'PendingPaymentReissuer: a reissued payment must be for a positive amount; use voidOnly() for zero.'
            );
        }

        $orderId = $pending->orderId();

        $this->voidOnly($pending, $occurredAt);

        $adapter = $this->adapterResolver->resolve($pending->method());
        $attempt = $adapter->charge($newAmount, new PaymentContext($orderId));

        $reissued = Payment::create($orderId, $pending->method(), $newAmount, PaymentStatus::PENDING);
        $reissued->recordAttemptResult(
            status: $attempt->status(),
            providerReference: $attempt->providerReference(),
            failureReason: $attempt->failureReason(),
            attemptedAt: $occurredAt,
        );

        $this->payments->save($reissued);

        return $reissued;
    }
}
