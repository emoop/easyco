<?php

namespace EasyCo\Payment\Contracts;

use EasyCo\Payment\PaymentRefund;

interface PaymentRefundRepository
{
    public function save(PaymentRefund $refund): void;

    public function findById(string $id): ?PaymentRefund;

    /** @return PaymentRefund[] */
    public function findByPaymentId(string $paymentId): array;
}
