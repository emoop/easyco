<?php

namespace EasyCo\Payment\Adapters;

use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentAttemptResult;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\PaymentRefundAttemptResult;
use EasyCo\Pricing\Money;

/**
 * A bank transfer sent directly by the customer — deterministic, never
 * calls any external system. See payment-domain-design.md §4.
 */
final class BankTransferPaymentMethodAdapter implements PaymentMethodAdapter
{
    /**
     * Always PENDING — waiting for the transfer to arrive, confirmed
     * manually later (the confirmation mechanism itself isn't built
     * yet, see design doc §7).
     */
    public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult
    {
        return PaymentAttemptResult::pending();
    }

    /**
     * Always COMPLETED immediately — a bank transfer sent back has no
     * external system to round-trip through, same reasoning as
     * CashOnDeliveryPaymentMethodAdapter::refund().
     */
    public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult
    {
        return PaymentRefundAttemptResult::completed();
    }
}
