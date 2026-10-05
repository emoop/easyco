<?php

namespace App\Services;

use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentReceipt;

/**
 * What PaymentReceiptRecorder::record() returns: the receipt (the first one, on a replay), the payment
 * as it stands after the call, whether this call settled it, and whether it was a repeat of an
 * operation already performed (then nothing was written and no hook fired).
 */
final class PaymentReceiptResult
{
    private function __construct(
        private readonly PaymentReceipt $receipt,
        private readonly Payment $payment,
        private readonly bool $settled,
        private readonly bool $replay,
    ) {
    }

    public static function performed(PaymentReceipt $receipt, Payment $payment, bool $settled): self
    {
        return new self($receipt, $payment, $settled, false);
    }

    public static function replayed(PaymentReceipt $receipt, Payment $payment): self
    {
        return new self($receipt, $payment, $payment->isSettled(), true);
    }

    public function receipt(): PaymentReceipt
    {
        return $this->receipt;
    }

    public function payment(): Payment
    {
        return $this->payment;
    }

    /** True when the payment is settled after this call (on a replay: as it stands now). */
    public function settled(): bool
    {
        return $this->settled;
    }

    public function wasReplay(): bool
    {
        return $this->replay;
    }
}
