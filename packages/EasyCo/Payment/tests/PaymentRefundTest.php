<?php

namespace EasyCo\Payment\Tests;

use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PaymentRefundTest extends TestCase
{
    private function amount(int $minor = 500): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    // --- construction / all three statuses ----------------------------------

    public function test_create_with_pending_status_succeeds(): void
    {
        $refund = PaymentRefund::create('payment-1', $this->amount(), PaymentRefundStatus::PENDING);

        $this->assertNull($refund->id());
        $this->assertSame('payment-1', $refund->paymentId());
        $this->assertSame(PaymentRefundStatus::PENDING, $refund->status());
        $this->assertNull($refund->reason());
        $this->assertNull($refund->refundedBy());
        $this->assertNull($refund->failureReason());
    }

    public function test_create_with_completed_status_succeeds(): void
    {
        $refund = PaymentRefund::create(
            'payment-1',
            $this->amount(),
            PaymentRefundStatus::COMPLETED,
            reason: 'defective',
            refundedBy: 'staff-7',
        );

        $this->assertSame(PaymentRefundStatus::COMPLETED, $refund->status());
        $this->assertSame('defective', $refund->reason());
        $this->assertSame('staff-7', $refund->refundedBy());
    }

    public function test_create_with_failed_status_succeeds(): void
    {
        $refund = PaymentRefund::create('payment-1', $this->amount(), PaymentRefundStatus::FAILED, failureReason: 'provider_timeout');

        $this->assertSame(PaymentRefundStatus::FAILED, $refund->status());
        $this->assertSame('provider_timeout', $refund->failureReason());
    }

    public function test_a_failed_refund_may_have_no_known_failure_reason(): void
    {
        $refund = PaymentRefund::create('payment-1', $this->amount(), PaymentRefundStatus::FAILED);

        $this->assertSame(PaymentRefundStatus::FAILED, $refund->status());
        $this->assertNull($refund->failureReason());
    }

    // --- paymentId non-empty, no existence check ----------------------------

    public function test_empty_payment_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::create('', $this->amount(), PaymentRefundStatus::PENDING);
    }

    public function test_whitespace_only_payment_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::create('   ', $this->amount(), PaymentRefundStatus::PENDING);
    }

    public function test_a_payment_id_referencing_no_real_payment_is_accepted(): void
    {
        // No existence check against a real Payment, by design — see
        // PaymentRefund's own docblock and design doc §3.
        $refund = PaymentRefund::create('nonexistent-payment-id', $this->amount(), PaymentRefundStatus::PENDING);

        $this->assertSame('nonexistent-payment-id', $refund->paymentId());
    }

    // --- amount must be positive --------------------------------------------

    public function test_zero_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::create('payment-1', $this->amount(0), PaymentRefundStatus::PENDING);
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::create('payment-1', $this->amount(-1), PaymentRefundStatus::PENDING);
    }

    // --- reason is free text, unvalidated ------------------------------------

    public function test_any_free_text_reason_is_accepted(): void
    {
        $refund = PaymentRefund::create('payment-1', $this->amount(), PaymentRefundStatus::COMPLETED, reason: 'goodwill gesture, not on any fixed list');

        $this->assertSame('goodwill gesture, not on any fixed list', $refund->reason());
    }

    // --- failureReason only meaningful when FAILED --------------------------

    public function test_failure_reason_on_pending_status_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::create('payment-1', $this->amount(), PaymentRefundStatus::PENDING, failureReason: 'not applicable');
    }

    public function test_failure_reason_on_completed_status_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::create('payment-1', $this->amount(), PaymentRefundStatus::COMPLETED, failureReason: 'not applicable');
    }

    // --- assignId() ------------------------------------------------------------

    public function test_id_can_only_be_assigned_once(): void
    {
        $refund = PaymentRefund::create('payment-1', $this->amount(), PaymentRefundStatus::PENDING);
        $refund->assignId('1');

        $this->assertSame('1', $refund->id());

        $this->expectException(LogicException::class);
        $refund->assignId('2');
    }

    // --- reconstituteFromStorage() -----------------------------------------

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $amount = $this->amount(750);

        $refund = PaymentRefund::reconstituteFromStorage(
            id: '5',
            paymentId: 'payment-9',
            amount: $amount,
            reason: 'wrong item',
            refundedBy: 'staff-3',
            status: PaymentRefundStatus::COMPLETED,
            failureReason: null,
        );

        $this->assertSame('5', $refund->id());
        $this->assertSame('payment-9', $refund->paymentId());
        $this->assertSame($amount, $refund->amount());
        $this->assertSame('wrong item', $refund->reason());
        $this->assertSame('staff-3', $refund->refundedBy());
        $this->assertSame(PaymentRefundStatus::COMPLETED, $refund->status());
        $this->assertNull($refund->failureReason());
    }
}
