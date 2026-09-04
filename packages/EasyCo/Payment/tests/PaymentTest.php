<?php

namespace EasyCo\Payment\Tests;

use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    private function amount(int $minor = 1000): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    // --- construction / all three statuses ----------------------------------

    public function test_create_with_pending_status_succeeds(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);

        $this->assertNull($payment->id());
        $this->assertSame('order-1', $payment->orderId());
        $this->assertSame('cash_on_delivery', $payment->method());
        $this->assertSame(PaymentStatus::PENDING, $payment->status());
        $this->assertNull($payment->providerReference());
        $this->assertNull($payment->failureReason());
    }

    public function test_create_with_captured_status_succeeds(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::CAPTURED, providerReference: 'ch_123');

        $this->assertSame(PaymentStatus::CAPTURED, $payment->status());
        $this->assertSame('ch_123', $payment->providerReference());
    }

    public function test_create_with_failed_status_succeeds(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::FAILED, failureReason: 'card_declined');

        $this->assertSame(PaymentStatus::FAILED, $payment->status());
        $this->assertSame('card_declined', $payment->failureReason());
    }

    public function test_a_failed_payment_may_have_no_known_failure_reason(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::FAILED);

        $this->assertSame(PaymentStatus::FAILED, $payment->status());
        $this->assertNull($payment->failureReason());
    }

    // --- orderId / method non-empty -----------------------------------------

    public function test_empty_order_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Payment::create('', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
    }

    public function test_whitespace_only_order_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Payment::create('   ', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
    }

    public function test_empty_method_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Payment::create('order-1', '', $this->amount(), PaymentStatus::PENDING);
    }

    public function test_a_free_form_method_not_on_any_fixed_list_is_accepted(): void
    {
        $payment = Payment::create('order-1', 'literally_anything', $this->amount(), PaymentStatus::PENDING);

        $this->assertSame('literally_anything', $payment->method());
    }

    // --- amount must be positive --------------------------------------------

    public function test_zero_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Payment::create('order-1', 'cash_on_delivery', $this->amount(0), PaymentStatus::PENDING);
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Payment::create('order-1', 'cash_on_delivery', $this->amount(-1), PaymentStatus::PENDING);
    }

    // --- failureReason only meaningful when FAILED --------------------------

    public function test_failure_reason_on_pending_status_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING, failureReason: 'not applicable');
    }

    public function test_failure_reason_on_captured_status_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::CAPTURED, failureReason: 'not applicable');
    }

    // --- assignId() ------------------------------------------------------------

    public function test_id_can_only_be_assigned_once(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $payment->assignId('1');

        $this->assertSame('1', $payment->id());

        $this->expectException(LogicException::class);
        $payment->assignId('2');
    }

    // --- reconstituteFromStorage() -----------------------------------------

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $amount = $this->amount(2500);

        $payment = Payment::reconstituteFromStorage(
            id: '9',
            orderId: 'order-42',
            method: 'bank_transfer',
            amount: $amount,
            status: PaymentStatus::CAPTURED,
            providerReference: 'ref-abc',
            failureReason: null,
        );

        $this->assertSame('9', $payment->id());
        $this->assertSame('order-42', $payment->orderId());
        $this->assertSame('bank_transfer', $payment->method());
        $this->assertSame($amount, $payment->amount());
        $this->assertSame(PaymentStatus::CAPTURED, $payment->status());
        $this->assertSame('ref-abc', $payment->providerReference());
        $this->assertNull($payment->failureReason());
    }
}
