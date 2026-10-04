<?php

namespace EasyCo\Payment;

use DateTimeImmutable;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\Exceptions\InvalidRefundTransitionException;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;

/**
 * A refund against a specific successful Payment — see
 * payment-domain-design.md §3 and, for the refund model,
 * shipping-domain-design.md §7.2. Mirrors EasyCo\Promotions\Promotion's shape
 * exactly, same as Payment itself: private constructor, named assertion
 * methods, a public create() factory, reconstituteFromStorage() for the
 * persistence layer, and a one-time assignId().
 *
 * THE TOTAL IS `amount`; it must equal the breakdown's
 * goods + shipping + adjustment − deduction (RefundBreakdown). A refund carries
 * its order id (a plain string, copied from the payment at creation, never
 * validated against a real Order — cross-domain by id), the payout channel
 * (cash or bank), and the per-line goods rows inside the breakdown.
 *
 * paymentId/orderId ARE NOT VALIDATED AGAINST REAL ROWS AT CONSTRUCTION —
 * same cross-domain-by-id posture PromotionScope already takes toward ids it
 * references (design doc §3's own note); enforcing that the referenced Payment
 * is actually CAPTURED, and that this refund doesn't exceed its captured
 * amount, is the caller's/an application service's job (design doc §5.2 — not
 * purely DB-enforceable, so not this class's job either).
 *
 * Money DOES NOT GUARD AGAINST ZERO/NEGATIVE AMOUNTS AT CONSTRUCTION — this
 * class enforces amount->isPositive() itself, same as Payment: a refund whose
 * total would be 0 moves no money and is simply not created.
 *
 * The paid-out fields (date, reference, note, who) belong to PAID_OUT only,
 * the cancellation fields (when, why, who) to CANCELLED only. Both are set by the
 * two transitions out of OWED (refunds R2a, §7.2.16): markPaidOut() and
 * cancelOwed(). Nothing leaves PAID_OUT or CANCELLED: a paid-out refund (a legacy
 * row mapped to PAID_OUT included) is a fact, and a cancelled one is final.
 */
final class PaymentRefund
{
    private function __construct(
        private ?string $id,
        private readonly string $paymentId,
        private readonly string $orderId,
        private readonly Money $amount,
        private readonly RefundChannel $channel,
        private readonly RefundBreakdown $breakdown,
        private readonly ?string $reason,
        private readonly ?string $refundedBy,
        private PaymentRefundStatus $status,
        private readonly ?string $failureReason,
        private ?DateTimeImmutable $paidOutAt,
        private ?string $paidOutReference,
        private ?string $paidOutNote,
        private ?string $paidOutBy,
        private ?DateTimeImmutable $cancelledAt = null,
        private ?string $cancelledReason = null,
        private ?string $cancelledBy = null,
    ) {
        self::assertNotEmpty('paymentId', $paymentId);
        self::assertNotEmpty('orderId', $orderId);
        self::assertPositiveAmount($amount);
        self::assertFailureReasonMatchesStatus($status, $failureReason);
        self::assertBreakdownMatchesAmount($amount, $breakdown);
        self::assertPaidOutFieldsMatchStatus($status, $paidOutAt, $paidOutReference, $paidOutNote, $paidOutBy);
        self::assertCancellationFieldsMatchStatus($status, $cancelledAt, $cancelledReason, $cancelledBy);
    }

    private static function assertNotEmpty(string $fieldName, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("PaymentRefund {$fieldName} must not be empty.");
        }
    }

    private static function assertPositiveAmount(Money $amount): void
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('PaymentRefund amount must be positive; zero or negative amounts are rejected.');
        }
    }

    /**
     * Only the one direction is enforced: a non-null failureReason
     * implies status = FAILED. Same one-directional rule as Payment's
     * own assertFailureReasonMatchesStatus() — see that method's
     * docblock.
     */
    private static function assertFailureReasonMatchesStatus(PaymentRefundStatus $status, ?string $failureReason): void
    {
        if ($failureReason !== null && $status !== PaymentRefundStatus::FAILED) {
            throw new InvalidArgumentException('PaymentRefund failureReason may only be set when status is FAILED.');
        }
    }

    private static function assertBreakdownMatchesAmount(Money $amount, RefundBreakdown $breakdown): void
    {
        if (! $breakdown->goods->currency()->equals($amount->currency())) {
            throw new InvalidArgumentException('PaymentRefund breakdown is in a different currency than the refund amount.');
        }

        if (! $breakdown->total()->equals($amount)) {
            throw new InvalidArgumentException(sprintf(
                'PaymentRefund amount (%d) must equal goods + shipping + adjustment - deduction (%d).',
                $amount->minorValue(),
                $breakdown->total()->minorValue(),
            ));
        }
    }

    private static function assertPaidOutFieldsMatchStatus(
        PaymentRefundStatus $status,
        ?DateTimeImmutable $paidOutAt,
        ?string $reference,
        ?string $note,
        ?string $by,
    ): void {
        if ($status === PaymentRefundStatus::PAID_OUT) {
            if ($paidOutAt === null) {
                throw new InvalidArgumentException('PaymentRefund with status PAID_OUT requires paidOutAt.');
            }

            return;
        }

        if ($paidOutAt !== null || $reference !== null || $note !== null || $by !== null) {
            throw new InvalidArgumentException('PaymentRefund paid-out fields may only be set when status is PAID_OUT.');
        }
    }

    private static function assertCancellationFieldsMatchStatus(PaymentRefundStatus $status, ?DateTimeImmutable $at, ?string $reason, ?string $by): void
    {
        if ($status !== PaymentRefundStatus::CANCELLED && ($at !== null || $reason !== null || $by !== null)) {
            throw new InvalidArgumentException('PaymentRefund cancellation fields may only be set when status is CANCELLED.');
        }

        if (($at === null) !== ($reason === null)) {
            throw new InvalidArgumentException('PaymentRefund cancelledAt and cancelledReason are set together or not at all.');
        }
    }

    /**
     * $breakdown null means "all goods, no per-line rows" — a plain amount.
     * A refund is never created PAID_OUT (that is R2's transition and the
     * legacy migration's mapping), so there are no paid-out parameters here.
     */
    public static function create(
        string $paymentId,
        string $orderId,
        Money $amount,
        PaymentRefundStatus $status,
        RefundChannel $channel,
        ?RefundBreakdown $breakdown = null,
        ?string $reason = null,
        ?string $refundedBy = null,
        ?string $failureReason = null,
    ): self {
        return new self(
            id: null,
            paymentId: $paymentId,
            orderId: $orderId,
            amount: $amount,
            channel: $channel,
            breakdown: $breakdown ?? RefundBreakdown::goodsOnly($amount),
            reason: $reason,
            refundedBy: $refundedBy,
            status: $status,
            failureReason: $failureReason,
            paidOutAt: null,
            paidOutReference: null,
            paidOutNote: null,
            paidOutBy: null,
        );
    }

    /**
     * Reconstitutes a PaymentRefund exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $paymentId,
        string $orderId,
        Money $amount,
        RefundChannel $channel,
        RefundBreakdown $breakdown,
        ?string $reason,
        ?string $refundedBy,
        PaymentRefundStatus $status,
        ?string $failureReason,
        ?DateTimeImmutable $paidOutAt = null,
        ?string $paidOutReference = null,
        ?string $paidOutNote = null,
        ?string $paidOutBy = null,
        ?DateTimeImmutable $cancelledAt = null,
        ?string $cancelledReason = null,
        ?string $cancelledBy = null,
    ): self {
        return new self(
            id: $id,
            paymentId: $paymentId,
            orderId: $orderId,
            amount: $amount,
            channel: $channel,
            breakdown: $breakdown,
            reason: $reason,
            refundedBy: $refundedBy,
            status: $status,
            failureReason: $failureReason,
            paidOutAt: $paidOutAt,
            paidOutReference: $paidOutReference,
            paidOutNote: $paidOutNote,
            paidOutBy: $paidOutBy,
            cancelledAt: $cancelledAt,
            cancelledReason: $cancelledReason,
            cancelledBy: $cancelledBy,
        );
    }

    /**
     * OWED -> PAID_OUT (refunds R2a, §7.2.16): the merchant has paid the money back
     * and records it. The AMOUNT IS NOT AN INPUT: PAID_OUT confirms exactly the owed
     * total. $paidOutAt is the payout date as entered, and may not be after $now (the
     * caller's own clock, never read here). A BANK refund requires its payment
     * reference; for cash it is optional. A blank reference or note is stored as null.
     *
     * @throws InvalidRefundTransitionException
     */
    public function markPaidOut(DateTimeImmutable $paidOutAt, ?string $reference, ?string $note, string $by, DateTimeImmutable $now): void
    {
        if ($this->status !== PaymentRefundStatus::OWED) {
            throw InvalidRefundTransitionException::notOwed($this->status);
        }

        if (trim($by) === '') {
            throw InvalidRefundTransitionException::actorRequired();
        }

        if ($paidOutAt > $now) {
            throw InvalidRefundTransitionException::payoutInFuture();
        }

        $reference = trim((string) $reference) === '' ? null : $reference;

        if ($this->channel === RefundChannel::BANK && $reference === null) {
            throw InvalidRefundTransitionException::bankReferenceRequired();
        }

        $this->status = PaymentRefundStatus::PAID_OUT;
        $this->paidOutAt = $paidOutAt;
        $this->paidOutReference = $reference;
        $this->paidOutNote = trim((string) $note) === '' ? null : $note;
        $this->paidOutBy = $by;
    }

    /**
     * OWED -> CANCELLED (refunds R2a, §7.2.16): the refund is called off before any
     * money moved, with a mandatory reason. A PAID_OUT refund can never be cancelled.
     * Its room in every cap is freed by the state alone (CANCELLED does not count).
     *
     * @throws InvalidRefundTransitionException
     */
    public function cancelOwed(DateTimeImmutable $at, string $reason, string $by): void
    {
        if ($this->status !== PaymentRefundStatus::OWED) {
            throw InvalidRefundTransitionException::notOwed($this->status);
        }

        if (trim($reason) === '') {
            throw InvalidRefundTransitionException::reasonRequired();
        }

        if (trim($by) === '') {
            throw InvalidRefundTransitionException::actorRequired();
        }

        $this->status = PaymentRefundStatus::CANCELLED;
        $this->cancelledAt = $at;
        $this->cancelledReason = $reason;
        $this->cancelledBy = $by;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('PaymentRefund already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function paymentId(): string
    {
        return $this->paymentId;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    /** The refund TOTAL (goods + shipping + adjustment − deduction). */
    public function amount(): Money
    {
        return $this->amount;
    }

    public function channel(): RefundChannel
    {
        return $this->channel;
    }

    public function breakdown(): RefundBreakdown
    {
        return $this->breakdown;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function refundedBy(): ?string
    {
        return $this->refundedBy;
    }

    public function status(): PaymentRefundStatus
    {
        return $this->status;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function paidOutAt(): ?DateTimeImmutable
    {
        return $this->paidOutAt;
    }

    public function paidOutReference(): ?string
    {
        return $this->paidOutReference;
    }

    public function paidOutNote(): ?string
    {
        return $this->paidOutNote;
    }

    public function paidOutBy(): ?string
    {
        return $this->paidOutBy;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancelledReason(): ?string
    {
        return $this->cancelledReason;
    }

    public function cancelledBy(): ?string
    {
        return $this->cancelledBy;
    }
}
