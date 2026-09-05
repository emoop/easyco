<?php

namespace EasyCo\Payment\Adapters;

use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentAttemptResult;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\PaymentRefundAttemptResult;
use EasyCo\Pricing\Money;

/**
 * Cash physically collected at delivery — deterministic, never calls any
 * external system. See payment-domain-design.md §4.
 */
final class CashOnDeliveryPaymentMethodAdapter implements PaymentMethodAdapter
{
    /**
     * Always PENDING, no providerReference — payment happens physically
     * at delivery, confirmed later (the confirmation mechanism itself
     * isn't built yet, see design doc §7).
     */
    public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult
    {
        return PaymentAttemptResult::pending();
    }

    /**
     * Always COMPLETED immediately — a physical cash handback has no
     * external system to round-trip through.
     */
    public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult
    {
        return PaymentRefundAttemptResult::completed();
    }
}
