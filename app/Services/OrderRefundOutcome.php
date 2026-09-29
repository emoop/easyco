<?php

namespace App\Services;

use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentRefund;

/**
 * What App\Services\OrderRefunder::refund() actually did — added so the
 * future OrderStatusChanger integration can pick the right order_events
 * type (order-lifecycle-design.md §7.3's own table: REFUNDED for a
 * completed refund, PAYMENT_VOIDED for a void, nothing at all for R8(c))
 * and hand the right payload to the order.refunded hook, without
 * re-deriving what happened from a second query.
 *
 * FOUR SHAPES, ONE PER BRANCH — a plain value object, no behaviour beyond
 * reading what it was built with. `refunded()` is R8(a); `voidedAndReissued()`
 * and `voidedOnly()` are the two ways R8(b) can end (a positive remainder
 * reissues, a zero remainder does not); `nothingRecorded()` is R8(c).
 * isVoided() is true for BOTH void shapes, matching the single
 * `payment_voided` event type both of them write in the future caller —
 * a caller that only cares "was anything voided" does not need to branch
 * on whether a remainder existed.
 */
final class OrderRefundOutcome
{
    private function __construct(
        private readonly ?PaymentRefund $refund,
        private readonly ?Payment $voidedPayment,
        private readonly ?Payment $reissuedPayment,
        private readonly bool $isRefunded,
        private readonly bool $isVoided,
    ) {}

    public static function refunded(PaymentRefund $refund): self
    {
        return new self(
            refund: $refund,
            voidedPayment: null,
            reissuedPayment: null,
            isRefunded: true,
            isVoided: false,
        );
    }

    public static function voidedAndReissued(Payment $voided, Payment $reissued): self
    {
        return new self(
            refund: null,
            voidedPayment: $voided,
            reissuedPayment: $reissued,
            isRefunded: false,
            isVoided: true,
        );
    }

    public static function voidedOnly(Payment $voided): self
    {
        return new self(
            refund: null,
            voidedPayment: $voided,
            reissuedPayment: null,
            isRefunded: false,
            isVoided: true,
        );
    }

    public static function nothingRecorded(): self
    {
        return new self(
            refund: null,
            voidedPayment: null,
            reissuedPayment: null,
            isRefunded: false,
            isVoided: false,
        );
    }

    /** True only for refunded() — R8(a). */
    public function isRefunded(): bool
    {
        return $this->isRefunded;
    }

    /** True for voidedAndReissued() AND voidedOnly() — both are R8(b), one payment_voided event either way. */
    public function isVoided(): bool
    {
        return $this->isVoided;
    }

    public function refund(): ?PaymentRefund
    {
        return $this->refund;
    }

    public function voidedPayment(): ?Payment
    {
        return $this->voidedPayment;
    }

    /** Null for voidedOnly() — a zero remainder voids nothing back into existence. */
    public function reissuedPayment(): ?Payment
    {
        return $this->reissuedPayment;
    }
}
