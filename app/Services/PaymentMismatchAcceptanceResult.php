<?php

namespace App\Services;

use EasyCo\Payment\Payment;

/**
 * What PaymentReceiptRecorder::acceptMismatch() returns: the payment as it stands (settled for the
 * accepted amount) and whether this call was a repeat of an operation already performed (then nothing
 * was written and no hook fired).
 */
final class PaymentMismatchAcceptanceResult
{
    public function __construct(
        private readonly Payment $payment,
        private readonly bool $replay,
    ) {
    }

    public function payment(): Payment
    {
        return $this->payment;
    }

    public function wasReplay(): bool
    {
        return $this->replay;
    }
}
