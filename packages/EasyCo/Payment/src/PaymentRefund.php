<?php

namespace EasyCo\Payment;

use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;

/**
 * A refund against a specific successful Payment — see
 * payment-domain-design.md §3 for the full field list and reasoning.
 * Mirrors EasyCo\Promotions\Promotion's shape exactly, same as Payment
 * itself: private constructor, named assertion methods, a public
 * create() factory, reconstituteFromStorage() for the persistence
 * layer, and a one-time assignId().
 *
 * paymentId IS NOT VALIDATED AGAINST A REAL Payment AT CONSTRUCTION —
 * same cross-domain-by-id posture PromotionScope already takes toward
 * ids it references (design doc §3's own note); enforcing that the
 * referenced Payment is actually CAPTURED, and that this refund doesn't
 * exceed its captured amount, is the caller's/an application service's
 * job (design doc §5.2 — not purely DB-enforceable, so not this class's
 * job either).
 *
 * Money DOES NOT GUARD AGAINST ZERO/NEGATIVE AMOUNTS AT CONSTRUCTION —
 * this class enforces amount->isPositive() itself, same as Payment.
 */
final class PaymentRefund
{
    private function __construct(
        private ?string $id,
        private readonly string $paymentId,
        private readonly Money $amount,
        private readonly ?string $reason,
        private readonly ?string $refundedBy,
        private readonly PaymentRefundStatus $status,
        private readonly ?string $failureReason,
    ) {
        self::assertNotEmpty('paymentId', $paymentId);
        self::assertPositiveAmount($amount);
        self::assertFailureReasonMatchesStatus($status, $failureReason);
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

    public static function create(
        string $paymentId,
        Money $amount,
        PaymentRefundStatus $status,
        ?string $reason = null,
        ?string $refundedBy = null,
        ?string $failureReason = null,
    ): self {
        return new self(
            id: null,
            paymentId: $paymentId,
            amount: $amount,
            reason: $reason,
            refundedBy: $refundedBy,
            status: $status,
            failureReason: $failureReason,
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
        Money $amount,
        ?string $reason,
        ?string $refundedBy,
        PaymentRefundStatus $status,
        ?string $failureReason,
    ): self {
        return new self(
            id: $id,
            paymentId: $paymentId,
            amount: $amount,
            reason: $reason,
            refundedBy: $refundedBy,
            status: $status,
            failureReason: $failureReason,
        );
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

    public function amount(): Money
    {
        return $this->amount;
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
}
