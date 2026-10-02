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
     * Always OWED — the refund is decided and recorded, the money is paid back by
     * the merchant later (shipping-domain-design.md §7.2.5); there is no external
     * system to round-trip through.
     */
    public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult
    {
        return PaymentRefundAttemptResult::owed();
    }

    public function isOffline(): bool
    {
        return true;
    }
}
