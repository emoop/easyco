<?php

namespace App\Services;

use App\Services\Exceptions\PaymentReceiptUnreconciledException;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;

/**
 * The ONE reader of "where does a bank-transfer payment stand against its receipts"
 * (shipping-domain-design.md §7.2.20 §3, §7): expected, the effective sum, the signed difference, the
 * number of effective receipts and the state. The receipt service decides with forPayment(), the
 * dialog hint of R4a-4 will show it, and the guards of cancel / return / edit / the old one-click
 * confirmation ask it — no arithmetic is repeated anywhere else.
 *
 * Reads only; writes nothing. Not scoped or memoised: a figure read here is read under the caller's
 * order lock, and a cached one could outlive it.
 */
final class PaymentReceiptReader
{
    public const BANK_TRANSFER = 'bank_transfer';

    public function __construct(
        private readonly PaymentReceiptRepository $receipts,
    ) {
    }

    /** The full status of $payment: one grouped sum plus the effective rows (at most 20). */
    public function forPayment(Payment $payment): PaymentReceiptStatus
    {
        $paymentId = (string) $payment->id();

        return new PaymentReceiptStatus(
            expected: $payment->amount(),
            received: $this->receipts->effectiveSum($paymentId, $payment->amount()->currency()->code()),
            effectiveCount: count($this->receipts->findEffectiveByPaymentId($paymentId)),
            settled: $payment->isSettled(),
        );
    }

    /**
     * The effective sum of a payment on which money is in hand but NOT reconciled — a bank-transfer
     * payment that is not settled, not voided, with at least one effective receipt — else null. At most
     * one read, and none at all for any other payment (cash on delivery, a settled, a voided one).
     */
    public function unreconciledSum(Payment $payment): ?Money
    {
        if ($payment->method() !== self::BANK_TRANSFER || $payment->isSettled() || $payment->isVoided()) {
            return null;
        }

        $sum = $this->receipts->effectiveSum((string) $payment->id(), $payment->amount()->currency()->code());

        return $sum->isPositive() ? $sum : null;
    }

    /**
     * The guard of every operation that would void, reissue or settle around a receipt in hand.
     *
     * @param  iterable<Payment>  $payments  an order's payments
     *
     * @throws PaymentReceiptUnreconciledException
     */
    public function assertReconciled(iterable $payments): void
    {
        foreach ($payments as $payment) {
            $sum = $this->unreconciledSum($payment);

            if ($sum !== null) {
                throw new PaymentReceiptUnreconciledException($sum);
            }
        }
    }
}
