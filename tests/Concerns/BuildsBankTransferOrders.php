<?php

namespace Tests\Concerns;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Refunds R4a-2: orders whose bank-transfer payment is still PENDING and answered — the state in which a
 * receipt can be recorded. 5 × 20.00 = 100.00 EUR expected; the order is placed 2026-09-28 09:00 UTC and
 * the fixture clock (at()) is 2026-09-28 12:00 UTC.
 */
trait BuildsBankTransferOrders
{
    use BuildsRefundableOrders;

    /** @return array{orderId: string, saleLineIds: list<string>, variationIds: list<string>, payment: Payment} */
    private function bankOrder(OrderStatus $status = OrderStatus::PLACED, string $method = 'bank_transfer', bool $answered = true): array
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], status: $status, method: $method, settle: false);

        $payment = Payment::create($order['orderId'], $method, $this->eur(10000), PaymentStatus::PENDING);

        if ($answered) {
            $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        }

        app(PaymentRepository::class)->save($payment);
        $order['payment'] = $payment;

        return $order;
    }

    /** Appends a receipt straight through the repository (fixture only: no rules, no events). */
    private function appendReceipt(Payment $payment, int $minor, string $reference = 'REF', ?PaymentReceipt $supersedes = null, string $day = '2026-09-28'): PaymentReceipt
    {
        $receipt = PaymentReceipt::create((string) $payment->id(), $this->eur($minor), $day, $reference, new DateTimeImmutable('2026-09-28 10:00:00'), null, $supersedes?->id());
        app(PaymentReceiptRepository::class)->save($receipt);

        return $receipt;
    }

    private function paymentRow(Payment $payment): object
    {
        return DB::table('payments')->where('id', $payment->id())->first();
    }

    private function freshPayment(Payment $payment): Payment
    {
        return app(PaymentRepository::class)->findById((string) $payment->id());
    }
}
