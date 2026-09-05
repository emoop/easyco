<?php

namespace EasyCo\Payment\Contracts;

use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentAttemptResult;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\PaymentRefundAttemptResult;
use EasyCo\Pricing\Money;

/**
 * The actual boundary between Checkout (once it exists) and any payment
 * method, online or offline — see payment-domain-design.md §4. Checkout
 * calls only this, never a concrete provider directly, never branches
 * on "is this online or offline."
 *
 * An adapter COMPUTES a result; it never persists anything itself — the
 * caller (an HTTP/orchestration layer, not built in this prompt) is the
 * one that constructs and saves a Payment/PaymentRefund from whichever
 * result comes back. Same "adapter computes, caller persists"
 * separation PromotionValidator/PromotionDiscountCalculator already
 * keep from their own callers.
 */
interface PaymentMethodAdapter
{
    public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult;

    public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult;
}
