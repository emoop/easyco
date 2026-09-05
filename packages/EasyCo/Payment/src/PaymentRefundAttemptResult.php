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

    public static function pending(): self
    {
        return new self(status: PaymentRefundStatus::PENDING, failureReason: null);
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
