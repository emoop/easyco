<?php

namespace App\Services;

use EasyCo\Payment\PaymentRefund;

/**
 * What OrderStatusChanger::cancel() / recordReturn() report: whether this call
 * was a REPLAY of an operation already performed under the same key (it wrote
 * nothing and returned the first result), and the refund the operation recorded,
 * if it recorded one (shipping-domain-design.md §7.2.3).
 */
final class OrderReturnResult
{
    private function __construct(
        private readonly bool $replayed,
        private readonly ?PaymentRefund $refund,
    ) {
    }

    public static function performed(?PaymentRefund $refund): self
    {
        return new self(false, $refund);
    }

    public static function replayed(?PaymentRefund $refund): self
    {
        return new self(true, $refund);
    }

    public function wasReplay(): bool
    {
        return $this->replayed;
    }

    /** The refund this operation recorded (or recorded when first performed); null if it recorded none. */
    public function refund(): ?PaymentRefund
    {
        return $this->refund;
    }
}
