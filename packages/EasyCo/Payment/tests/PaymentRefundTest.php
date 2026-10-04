<?php

namespace EasyCo\Payment\Tests;

use DateTimeImmutable;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\Exceptions\InvalidRefundTransitionException;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Payment\RefundBreakdown;
use EasyCo\Payment\RefundLine;
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

    private function make(
        Money $amount,
        PaymentRefundStatus $status = PaymentRefundStatus::OWED,
        string $paymentId = 'payment-1',
        ?RefundBreakdown $breakdown = null,
        ?string $reason = null,
        ?string $refundedBy = null,
        ?string $failureReason = null,
    ): PaymentRefund {
        return PaymentRefund::create($paymentId, 'order-1', $amount, $status, RefundChannel::BANK, $breakdown, $reason, $refundedBy, $failureReason);
    }

    // --- construction / every status ----------------------------------------

    public function test_create_with_owed_status_succeeds(): void
    {
        $refund = $this->make($this->amount());

        $this->assertNull($refund->id());
        $this->assertSame('payment-1', $refund->paymentId());
        $this->assertSame('order-1', $refund->orderId());
        $this->assertSame(PaymentRefundStatus::OWED, $refund->status());
        $this->assertSame(RefundChannel::BANK, $refund->channel());
        $this->assertNull($refund->reason());
        $this->assertNull($refund->refundedBy());
        $this->assertNull($refund->failureReason());
        $this->assertNull($refund->paidOutAt());
    }

    public function test_create_with_requested_and_completed_status_succeeds(): void
    {
        $this->assertSame(PaymentRefundStatus::REQUESTED, $this->make($this->amount(), PaymentRefundStatus::REQUESTED)->status());

        $refund = $this->make($this->amount(), PaymentRefundStatus::COMPLETED, reason: 'defective', refundedBy: 'staff-7');

        $this->assertSame(PaymentRefundStatus::COMPLETED, $refund->status());
        $this->assertSame('defective', $refund->reason());
        $this->assertSame('staff-7', $refund->refundedBy());
    }

    public function test_create_with_failed_status_succeeds(): void
    {
        $refund = $this->make($this->amount(), PaymentRefundStatus::FAILED, failureReason: 'provider_timeout');

        $this->assertSame(PaymentRefundStatus::FAILED, $refund->status());
        $this->assertSame('provider_timeout', $refund->failureReason());
    }

    public function test_a_failed_refund_may_have_no_known_failure_reason(): void
    {
        $refund = $this->make($this->amount(), PaymentRefundStatus::FAILED);

        $this->assertSame(PaymentRefundStatus::FAILED, $refund->status());
        $this->assertNull($refund->failureReason());
    }

    // --- ids non-empty, no existence check ----------------------------------

    public function test_empty_or_blank_payment_id_throws(): void
    {
        foreach (['', '   '] as $paymentId) {
            try {
                $this->make($this->amount(), paymentId: $paymentId);
                $this->fail('a blank paymentId must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_empty_order_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orderId');

        PaymentRefund::create('payment-1', ' ', $this->amount(), PaymentRefundStatus::OWED, RefundChannel::CASH);
    }

    public function test_a_payment_id_referencing_no_real_payment_is_accepted(): void
    {
        // No existence check against a real Payment, by design — see
        // PaymentRefund's own docblock and design doc §3.
        $refund = $this->make($this->amount(), paymentId: 'nonexistent-payment-id');

        $this->assertSame('nonexistent-payment-id', $refund->paymentId());
    }

    // --- amount must be positive: a refund of 0 is simply not created -----------

    public function test_zero_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make($this->amount(0));
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make($this->amount(-1));
    }

    // --- reason is free text, unvalidated ------------------------------------

    public function test_any_free_text_reason_is_accepted(): void
    {
        $refund = $this->make($this->amount(), PaymentRefundStatus::COMPLETED, reason: 'goodwill gesture, not on any fixed list');

        $this->assertSame('goodwill gesture, not on any fixed list', $refund->reason());
    }

    // --- failureReason only meaningful when FAILED --------------------------

    public function test_failure_reason_on_a_non_failed_status_throws(): void
    {
        foreach ([PaymentRefundStatus::OWED, PaymentRefundStatus::REQUESTED, PaymentRefundStatus::COMPLETED] as $status) {
            try {
                $this->make($this->amount(), $status, failureReason: 'not applicable');
                $this->fail('a failureReason on a non-FAILED refund must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- the breakdown: total = goods + shipping + adjustment - deduction ------------

    public function test_a_plain_amount_is_all_goods_with_no_per_line_rows(): void
    {
        $breakdown = $this->make($this->amount(750))->breakdown();

        $this->assertSame(750, $breakdown->goods->minorValue());
        $this->assertTrue($breakdown->shipping->isZero());
        $this->assertTrue($breakdown->adjustment->isZero());
        $this->assertTrue($breakdown->deduction->isZero());
        $this->assertSame([], $breakdown->lines);
    }

    public function test_the_total_must_equal_goods_plus_shipping_plus_adjustment_minus_deduction(): void
    {
        $breakdown = new RefundBreakdown($this->amount(1000), $this->amount(300), $this->amount(0), $this->amount(200), 'restocking fee');

        $this->assertSame(1100, $breakdown->total()->minorValue());
        $this->assertSame(1100, $this->make($this->amount(1100), breakdown: $breakdown)->amount()->minorValue());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must equal goods + shipping + adjustment - deduction');

        $this->make($this->amount(1000), breakdown: $breakdown);
    }

    public function test_a_breakdown_in_another_currency_than_the_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make($this->amount(500), breakdown: RefundBreakdown::goodsOnly(Money::fromMinorUnits(500, 'USD')));
    }

    // --- the paid-out facts belong to PAID_OUT only ------------------------------------

    public function test_paid_out_facts_on_a_refund_that_is_not_paid_out_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::reconstituteFromStorage(
            id: '1', paymentId: 'p', orderId: 'o', amount: $this->amount(), channel: RefundChannel::BANK,
            breakdown: RefundBreakdown::goodsOnly($this->amount()), reason: null, refundedBy: null,
            status: PaymentRefundStatus::OWED, failureReason: null, paidOutAt: new DateTimeImmutable('2026-01-01'),
        );
    }

    public function test_a_paid_out_refund_requires_its_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::reconstituteFromStorage(
            id: '1', paymentId: 'p', orderId: 'o', amount: $this->amount(), channel: RefundChannel::BANK,
            breakdown: RefundBreakdown::goodsOnly($this->amount()), reason: null, refundedBy: null,
            status: PaymentRefundStatus::PAID_OUT, failureReason: null,
        );
    }

    // --- assignId() ------------------------------------------------------------

    public function test_id_can_only_be_assigned_once(): void
    {
        $refund = $this->make($this->amount());
        $refund->assignId('1');

        $this->assertSame('1', $refund->id());

        $this->expectException(LogicException::class);
        $refund->assignId('2');
    }

    // --- reconstituteFromStorage() -----------------------------------------

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $amount = $this->amount(750);
        $breakdown = new RefundBreakdown($this->amount(750), $this->amount(0), $this->amount(0), $this->amount(0), null, [new RefundLine('11', $this->amount(750))]);
        $paidOutAt = new DateTimeImmutable('2026-09-30 18:41:30');

        $refund = PaymentRefund::reconstituteFromStorage(
            id: '5',
            paymentId: 'payment-9',
            orderId: 'order-4',
            amount: $amount,
            channel: RefundChannel::CASH,
            breakdown: $breakdown,
            reason: 'wrong item',
            refundedBy: 'staff-3',
            status: PaymentRefundStatus::PAID_OUT,
            failureReason: null,
            paidOutAt: $paidOutAt,
            paidOutReference: 'ref-1',
            paidOutNote: 'paid at the register',
            paidOutBy: 'staff-3',
        );

        $this->assertSame('5', $refund->id());
        $this->assertSame('payment-9', $refund->paymentId());
        $this->assertSame('order-4', $refund->orderId());
        $this->assertSame($amount, $refund->amount());
        $this->assertSame(RefundChannel::CASH, $refund->channel());
        $this->assertSame($breakdown, $refund->breakdown());
        $this->assertSame('wrong item', $refund->reason());
        $this->assertSame('staff-3', $refund->refundedBy());
        $this->assertSame(PaymentRefundStatus::PAID_OUT, $refund->status());
        $this->assertNull($refund->failureReason());
        $this->assertSame($paidOutAt, $refund->paidOutAt());
        $this->assertSame('ref-1', $refund->paidOutReference());
        $this->assertSame('paid at the register', $refund->paidOutNote());
        $this->assertSame('staff-3', $refund->paidOutBy());
    }

    // --- the two transitions out of OWED (refunds R2a) ----------------------------------------------------------------

    private function owed(RefundChannel $channel = RefundChannel::BANK): PaymentRefund
    {
        return PaymentRefund::create('payment-1', 'order-1', $this->amount(1500), PaymentRefundStatus::OWED, $channel);
    }

    private function t(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    public function test_mark_paid_out_sets_every_paid_out_fact_and_keeps_the_owed_total(): void
    {
        $refund = $this->owed();

        $refund->markPaidOut($this->t('2026-10-02 09:00:00'), 'REF-77', 'paid by the accountant', 'staff-3', $this->t('2026-10-03 12:00:00'));

        $this->assertSame(PaymentRefundStatus::PAID_OUT, $refund->status());
        $this->assertSame('2026-10-02 09:00:00', $refund->paidOutAt()->format('Y-m-d H:i:s'));
        $this->assertSame('REF-77', $refund->paidOutReference());
        $this->assertSame('paid by the accountant', $refund->paidOutNote());
        $this->assertSame('staff-3', $refund->paidOutBy());
        $this->assertSame(1500, $refund->amount()->minorValue(), 'the amount is not an input: PAID_OUT confirms exactly the owed total');
    }

    public function test_a_cash_payout_needs_no_reference_and_a_blank_one_is_stored_as_null(): void
    {
        $refund = $this->owed(RefundChannel::CASH);

        $refund->markPaidOut($this->t('2026-10-03 11:00:00'), '  ', '', 'staff-3', $this->t('2026-10-03 12:00:00'));

        $this->assertSame(PaymentRefundStatus::PAID_OUT, $refund->status());
        $this->assertNull($refund->paidOutReference());
        $this->assertNull($refund->paidOutNote());
    }

    public function test_a_bank_payout_requires_a_reference(): void
    {
        foreach ([null, '', '   '] as $reference) {
            $refund = $this->owed(RefundChannel::BANK);

            try {
                $refund->markPaidOut($this->t('2026-10-03 11:00:00'), $reference, null, 'staff-3', $this->t('2026-10-03 12:00:00'));
                $this->fail('a bank payout without a reference must be refused');
            } catch (InvalidRefundTransitionException $e) {
                $this->assertSame(InvalidRefundTransitionException::BANK_REFERENCE_REQUIRED, $e->reason);
                $this->assertSame(PaymentRefundStatus::OWED, $refund->status(), 'a refused transition changes nothing');
            }
        }
    }

    public function test_a_payout_date_in_the_future_is_refused_and_now_itself_is_fine(): void
    {
        $refund = $this->owed(RefundChannel::CASH);

        try {
            $refund->markPaidOut($this->t('2026-10-03 12:00:01'), null, null, 'staff-3', $this->t('2026-10-03 12:00:00'));
            $this->fail('a future payout date must be refused');
        } catch (InvalidRefundTransitionException $e) {
            $this->assertSame(InvalidRefundTransitionException::PAYOUT_IN_FUTURE, $e->reason);
        }

        $refund->markPaidOut($this->t('2026-10-03 12:00:00'), null, null, 'staff-3', $this->t('2026-10-03 12:00:00'));
        $this->assertSame(PaymentRefundStatus::PAID_OUT, $refund->status());
    }

    public function test_only_an_owed_refund_can_be_paid_out_or_cancelled(): void
    {
        foreach ([PaymentRefundStatus::PAID_OUT, PaymentRefundStatus::CANCELLED, PaymentRefundStatus::REQUESTED, PaymentRefundStatus::COMPLETED, PaymentRefundStatus::FAILED] as $status) {
            $refund = PaymentRefund::reconstituteFromStorage(
                '1', 'payment-1', 'order-1', $this->amount(1500), RefundChannel::CASH, RefundBreakdown::goodsOnly($this->amount(1500)), null, null, $status,
                $status === PaymentRefundStatus::FAILED ? 'declined' : null,
                $status === PaymentRefundStatus::PAID_OUT ? $this->t('2026-10-01 10:00:00') : null,
            );

            foreach ([
                fn () => $refund->markPaidOut($this->t('2026-10-02 10:00:00'), null, null, 'staff-3', $this->t('2026-10-03 10:00:00')),
                fn () => $refund->cancelOwed($this->t('2026-10-03 10:00:00'), 'why', 'staff-3'),
            ] as $transition) {
                try {
                    $transition();
                    $this->fail("a {$status->value} refund must not transition");
                } catch (InvalidRefundTransitionException $e) {
                    $this->assertSame(InvalidRefundTransitionException::NOT_OWED, $e->reason);
                    $this->assertSame($status, $refund->status());
                }
            }
        }
    }

    public function test_cancelling_an_owed_refund_records_when_why_and_by_whom_and_frees_its_room(): void
    {
        $refund = $this->owed();

        $refund->cancelOwed($this->t('2026-10-03 10:00:00'), 'customer withdrew the claim', 'staff-3');

        $this->assertSame(PaymentRefundStatus::CANCELLED, $refund->status());
        $this->assertSame('customer withdrew the claim', $refund->cancelledReason());
        $this->assertSame('staff-3', $refund->cancelledBy());
        $this->assertSame('2026-10-03 10:00:00', $refund->cancelledAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($refund->status()->counts(), 'a cancelled refund no longer counts toward any cap');
    }

    public function test_a_cancellation_needs_a_reason_and_an_actor(): void
    {
        foreach ([['', 'staff-3', InvalidRefundTransitionException::REASON_REQUIRED], ['  ', 'staff-3', InvalidRefundTransitionException::REASON_REQUIRED], ['why', '', InvalidRefundTransitionException::ACTOR_REQUIRED]] as [$reason, $by, $code]) {
            $refund = $this->owed();

            try {
                $refund->cancelOwed($this->t('2026-10-03 10:00:00'), $reason, $by);
                $this->fail('expected a refusal');
            } catch (InvalidRefundTransitionException $e) {
                $this->assertSame($code, $e->reason);
                $this->assertSame(PaymentRefundStatus::OWED, $refund->status());
            }
        }
    }

    public function test_cancellation_facts_belong_to_a_cancelled_refund_only(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRefund::reconstituteFromStorage(
            '1', 'payment-1', 'order-1', $this->amount(1500), RefundChannel::CASH, RefundBreakdown::goodsOnly($this->amount(1500)), null, null, PaymentRefundStatus::OWED, null,
            cancelledAt: $this->t('2026-10-03 10:00:00'), cancelledReason: 'x', cancelledBy: 'staff-3',
        );
    }
}
