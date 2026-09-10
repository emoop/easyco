<?php

namespace EasyCo\Payment\Tests;

use DateTimeImmutable;
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
        $attemptedAt = new DateTimeImmutable('2026-09-10 12:00:00');

        $payment = Payment::reconstituteFromStorage(
            id: '9',
            orderId: 'order-42',
            method: 'bank_transfer',
            amount: $amount,
            status: PaymentStatus::CAPTURED,
            providerReference: 'ref-abc',
            failureReason: null,
            attemptedAt: $attemptedAt,
        );

        $this->assertSame('9', $payment->id());
        $this->assertSame('order-42', $payment->orderId());
        $this->assertSame('bank_transfer', $payment->method());
        $this->assertSame($amount, $payment->amount());
        $this->assertSame(PaymentStatus::CAPTURED, $payment->status());
        $this->assertSame('ref-abc', $payment->providerReference());
        $this->assertNull($payment->failureReason());
        $this->assertSame($attemptedAt, $payment->attemptedAt());
    }

    // --- attemptedAt() / recordAttemptResult() ------------------------------

    public function test_create_leaves_attempted_at_null(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);

        $this->assertNull($payment->attemptedAt());
    }

    /**
     * NOT an edge case — this is the expected, normal outcome for BOTH
     * V1 adapters on every real checkout: PENDING is a legitimate final
     * answer for cash-on-delivery/bank-transfer, not a placeholder. Named
     * to make that explicit, since a status-based guard would have
     * wrongly rejected exactly this call.
     */
    public function test_record_attempt_result_with_pending_is_the_normal_v1_case_and_succeeds(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $attemptedAt = new DateTimeImmutable('2026-09-10 12:00:00');

        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, $attemptedAt);

        $this->assertSame(PaymentStatus::PENDING, $payment->status());
        $this->assertSame($attemptedAt, $payment->attemptedAt());
    }

    public function test_record_attempt_result_with_captured_succeeds(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $attemptedAt = new DateTimeImmutable('2026-09-10 12:00:00');

        $payment->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_123', null, $attemptedAt);

        $this->assertSame(PaymentStatus::CAPTURED, $payment->status());
        $this->assertSame('ch_123', $payment->providerReference());
        $this->assertSame($attemptedAt, $payment->attemptedAt());
    }

    public function test_record_attempt_result_with_failed_succeeds(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $attemptedAt = new DateTimeImmutable('2026-09-10 12:00:00');

        $payment->recordAttemptResult(PaymentStatus::FAILED, null, 'card_declined', $attemptedAt);

        $this->assertSame(PaymentStatus::FAILED, $payment->status());
        $this->assertSame('card_declined', $payment->failureReason());
        $this->assertSame($attemptedAt, $payment->attemptedAt());
    }

    /**
     * The guard lives on attemptedAt, not status — proven specifically
     * after a PENDING first call, since a status-based guard (rejecting
     * only if the CURRENT status isn't PENDING) would have missed this:
     * the first call leaves status PENDING, so a status-based check
     * would have wrongly allowed a second call through.
     */
    public function test_a_second_record_attempt_result_call_throws_even_after_a_pending_first_call(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-10 12:00:00'));

        $this->expectException(LogicException::class);

        $payment->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_123', null, new DateTimeImmutable('2026-09-10 12:05:00'));
    }

    public function test_record_attempt_result_with_a_failure_reason_and_a_non_failed_status_throws(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::PENDING);

        $this->expectException(InvalidArgumentException::class);

        $payment->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_123', 'not applicable', new DateTimeImmutable());
    }
}
