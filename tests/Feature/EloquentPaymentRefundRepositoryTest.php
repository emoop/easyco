<?php

namespace Tests\Feature;

use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Pricing\Money;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentPaymentRefundRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): PaymentRefundRepository
    {
        return app(PaymentRefundRepository::class);
    }

    private function amount(int $minor = 500): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    public function test_save_then_find_by_id_round_trips_a_payment_refund(): void
    {
        $refund = PaymentRefund::create(
            'payment-1',
            $this->amount(),
            PaymentRefundStatus::COMPLETED,
            reason: 'defective',
            refundedBy: 'staff-7',
        );

        $this->repository()->save($refund);

        $this->assertNotNull($refund->id());

        $reloaded = $this->repository()->findById($refund->id());
        $this->assertNotNull($reloaded);
        $this->assertSame('payment-1', $reloaded->paymentId());
        $this->assertSame(500, $reloaded->amount()->minorValue());
        $this->assertSame('EUR', $reloaded->amount()->currency()->code());
        $this->assertSame('defective', $reloaded->reason());
        $this->assertSame('staff-7', $reloaded->refundedBy());
        $this->assertSame(PaymentRefundStatus::COMPLETED, $reloaded->status());
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_find_by_payment_id_returns_every_matching_refund(): void
    {
        $first = PaymentRefund::create('payment-multi', $this->amount(200), PaymentRefundStatus::COMPLETED, reason: 'partial return');
        $second = PaymentRefund::create('payment-multi', $this->amount(100), PaymentRefundStatus::COMPLETED, reason: 'second partial return');
        $other = PaymentRefund::create('payment-other', $this->amount(), PaymentRefundStatus::PENDING);

        $this->repository()->save($first);
        $this->repository()->save($second);
        $this->repository()->save($other);

        $results = $this->repository()->findByPaymentId('payment-multi');

        $this->assertCount(2, $results);
        $ids = array_map(fn (PaymentRefund $refund) => $refund->id(), $results);
        $this->assertContains($first->id(), $ids);
        $this->assertContains($second->id(), $ids);
        $this->assertNotContains($other->id(), $ids);
    }

    /**
     * sumCompletedForPayment(): COMPLETED only — money that has not moved back
     * yet does not cap anything, so a pending or failed row is invisible to it
     * (order-lifecycle-design.md §7.3, R8(a)).
     */
    public function test_sum_completed_for_payment_counts_completed_refunds_only(): void
    {
        $this->repository()->save(PaymentRefund::create('payment-sum', $this->amount(200), PaymentRefundStatus::COMPLETED));
        $this->repository()->save(PaymentRefund::create('payment-sum', $this->amount(100), PaymentRefundStatus::COMPLETED));
        $this->repository()->save(PaymentRefund::create('payment-sum', $this->amount(700), PaymentRefundStatus::PENDING, reason: 'waiting on the provider'));
        $this->repository()->save(PaymentRefund::create('payment-sum', $this->amount(900), PaymentRefundStatus::FAILED, failureReason: 'declined'));
        $this->repository()->save(PaymentRefund::create('payment-other', $this->amount(5000), PaymentRefundStatus::COMPLETED));

        $sum = $this->repository()->sumCompletedForPayment('payment-sum', 'EUR');

        $this->assertSame(300, $sum->minorValue());
        $this->assertSame('EUR', $sum->currency()->code());
        $this->assertTrue($sum->equals(Money::fromMinorUnits(300, 'EUR')));
    }

    /**
     * Nothing refunded yet is a REAL answer, not a missing one: zero Money in
     * the caller's own currency, so R8(a)'s cap needs no null handling — the
     * same posture Money takes everywhere else in this codebase.
     */
    public function test_sum_completed_for_payment_is_zero_money_when_there_is_none(): void
    {
        $this->repository()->save(PaymentRefund::create('payment-none', $this->amount(200), PaymentRefundStatus::PENDING));

        $sum = $this->repository()->sumCompletedForPayment('payment-none', 'BGN');

        $this->assertTrue($sum->isZero());
        $this->assertSame(0, $sum->minorValue());
        $this->assertSame('BGN', $sum->currency()->code());

        // A payment id that has no rows at all behaves identically.
        $this->assertTrue($this->repository()->sumCompletedForPayment('payment-absent', 'EUR')->isZero());
    }

    /**
     * "Done in SQL, not by loading rows" is asserted rather than assumed: one
     * statement, and it computes a SUM — so a payment with a hundred refunds
     * costs the same as one with none (the shape §7.3's cap is written for).
     */
    public function test_sum_completed_for_payment_is_computed_in_sql_not_by_loading_rows(): void
    {
        $this->repository()->save(PaymentRefund::create('payment-sql', $this->amount(200), PaymentRefundStatus::COMPLETED));
        $this->repository()->save(PaymentRefund::create('payment-sql', $this->amount(300), PaymentRefundStatus::COMPLETED));

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->assertSame(500, $this->repository()->sumCompletedForPayment('payment-sql', 'EUR')->minorValue());

        $this->assertCount(1, $statements, 'the total is ONE statement, never a get() plus PHP arithmetic');
        $this->assertStringContainsString('sum(`amount_minor`)', strtolower($statements[0]));
        $this->assertStringNotContainsString('select *', strtolower($statements[0]));
    }
}
