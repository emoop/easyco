<?php

namespace EasyCo\Payment;

use EasyCo\Payment\Enums\PaymentRefundStatus;

/**
 * The outcome of PaymentMethodAdapter::refund() — carries exactly what
 * a PaymentRefund row needs the adapter to determine (status/
 * failureReason only; amount/reason/refundedBy are the caller's inputs,
 * not something an adapter computes). Same shape as PaymentAttemptResult.
 */
final class PaymentRefundAttemptResult
{
    private function __construct(
        private readonly PaymentRefundStatus $status,
        private readonly ?string $failureReason,
    ) {
    }

    /** An online refund asked of a provider and not yet answered (R5). */
    public static function requested(): self
    {
        return new self(status: PaymentRefundStatus::REQUESTED, failureReason: null);
    }

    /**
     * An OFFLINE refund: decided and dated, the money has not left yet. What
     * both shipped adapters return (shipping-domain-design.md §7.2.5).
     */
    public static function owed(): self
    {
        return new self(status: PaymentRefundStatus::OWED, failureReason: null);
    }

    public static function completed(): self
    {
        return new self(status: PaymentRefundStatus::COMPLETED, failureReason: null);
    }

    /**
     * Mirrors PaymentRefund's own one-directional failureReason rule —
     * a FAILED result may have no known reason.
     */
    public static function failed(?string $failureReason = null): self
    {
        return new self(status: PaymentRefundStatus::FAILED, failureReason: $failureReason);
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
