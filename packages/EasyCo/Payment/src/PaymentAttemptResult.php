<?php

namespace EasyCo\Payment;

use EasyCo\Payment\Enums\PaymentStatus;

/**
 * The outcome of PaymentMethodAdapter::charge() — carries exactly what
 * a Payment row needs beyond what the caller already knows (orderId/
 * method/amount are the caller's inputs, not the adapter's to decide).
 * Mirrors App\Services\PromotionValidationResult's shape: private
 * constructor, named static factories, no database awareness.
 */
final class PaymentAttemptResult
{
    private function __construct(
        private readonly PaymentStatus $status,
        private readonly ?string $providerReference,
        private readonly ?string $failureReason,
    ) {
    }

    /** No providerReference/failureReason make sense for a pending result. */
    public static function pending(): self
    {
        return new self(status: PaymentStatus::PENDING, providerReference: null, failureReason: null);
    }

    public static function captured(string $providerReference): self
    {
        return new self(status: PaymentStatus::CAPTURED, providerReference: $providerReference, failureReason: null);
    }

    /**
     * Mirrors Payment's own one-directional failureReason rule — a
     * FAILED result may have no known reason.
     */
    public static function failed(?string $failureReason = null): self
    {
        return new self(status: PaymentStatus::FAILED, providerReference: null, failureReason: $failureReason);
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }
}
