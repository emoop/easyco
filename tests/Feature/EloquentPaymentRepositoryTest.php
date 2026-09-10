<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\Persistence\Eloquent\PaymentModel;
use EasyCo\Pricing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentPaymentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): PaymentRepository
    {
        return app(PaymentRepository::class);
    }

    private function amount(int $minor = 1000): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    public function test_the_real_captured_order_id_generated_column_and_its_unique_index(): void
    {
        // Confirms the actual generated column/constraint this
        // repository's guarantee depends on — not just trusting the
        // migration file (CLAUDE.md rule 2/project convention).
        $createTable = DB::select('SHOW CREATE TABLE payments')[0]->{'Create Table'};

        $this->assertStringContainsString('`captured_order_id`', $createTable);
        $this->assertStringContainsString('GENERATED ALWAYS AS', $createTable);
        $this->assertStringContainsString('STORED', $createTable);
        $this->assertStringContainsString("case when (`status` = _utf8mb4'captured') then `order_id` else NULL end", $createTable);
        $this->assertStringContainsString('UNIQUE KEY `pay_captured_order_unique` (`captured_order_id`)', $createTable);
    }

    public function test_save_then_find_by_id_round_trips_a_payment(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);

        $this->repository()->save($payment);

        $this->assertNotNull($payment->id());

        $reloaded = $this->repository()->findById($payment->id());
        $this->assertNotNull($reloaded);
        $this->assertSame('order-1', $reloaded->orderId());
        $this->assertSame('cash_on_delivery', $reloaded->method());
        $this->assertSame(1000, $reloaded->amount()->minorValue());
        $this->assertSame('EUR', $reloaded->amount()->currency()->code());
        $this->assertSame(PaymentStatus::PENDING, $reloaded->status());
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_find_by_order_id_returns_every_attempt_for_that_order(): void
    {
        $first = Payment::create('order-retry', 'card_stripe', $this->amount(), PaymentStatus::FAILED, failureReason: 'card_declined');
        $second = Payment::create('order-retry', 'card_stripe', $this->amount(), PaymentStatus::CAPTURED, providerReference: 'ch_1');
        $other = Payment::create('order-other', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);

        $this->repository()->save($first);
        $this->repository()->save($second);
        $this->repository()->save($other);

        $results = $this->repository()->findByOrderId('order-retry');

        $this->assertCount(2, $results);
        $ids = array_map(fn (Payment $payment) => $payment->id(), $results);
        $this->assertContains($first->id(), $ids);
        $this->assertContains($second->id(), $ids);
        $this->assertNotContains($other->id(), $ids);
    }

    public function test_a_second_captured_payment_for_the_same_order_id_is_rejected_by_the_database_itself(): void
    {
        $first = Payment::create('order-double-capture', 'card_stripe', $this->amount(), PaymentStatus::CAPTURED, providerReference: 'ch_1');
        $this->repository()->save($first);

        $second = Payment::create('order-double-capture', 'card_stripe', $this->amount(), PaymentStatus::CAPTURED, providerReference: 'ch_2');

        // Proving the database engine itself rejects the second CAPTURED
        // row — a genuine QueryException from the pay_captured_order_unique
        // constraint, not an application-level check.
        $this->expectException(QueryException::class);

        $this->repository()->save($second);
    }

    public function test_two_pending_or_failed_payments_for_the_same_order_id_save_without_conflict(): void
    {
        $pending = Payment::create('order-multi-attempt', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $failed = Payment::create('order-multi-attempt', 'card_stripe', $this->amount(), PaymentStatus::FAILED, failureReason: 'timeout');

        $this->repository()->save($pending);
        $this->repository()->save($failed);

        $results = $this->repository()->findByOrderId('order-multi-attempt');
        $this->assertCount(2, $results);
    }

    public function test_attempted_at_round_trips_as_a_real_datetimeimmutable(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $this->repository()->save($payment);

        $attemptedAt = new DateTimeImmutable('2026-09-10 12:34:56');
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, $attemptedAt);
        $this->repository()->save($payment);

        $reloaded = $this->repository()->findById($payment->id());
        $this->assertInstanceOf(DateTimeImmutable::class, $reloaded->attemptedAt());
        $this->assertSame($attemptedAt->format('Y-m-d H:i:s'), $reloaded->attemptedAt()->format('Y-m-d H:i:s'));
    }

    public function test_a_freshly_created_payment_round_trips_a_null_attempted_at(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $this->repository()->save($payment);

        $reloaded = $this->repository()->findById($payment->id());
        $this->assertNull($reloaded->attemptedAt());
    }

    public function test_saving_a_payment_that_already_has_an_id_updates_the_existing_row_rather_than_inserting_a_second_one(): void
    {
        $payment = Payment::create('order-update-in-place', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $this->repository()->save($payment);
        $id = $payment->id();

        $payment->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_999', null, new DateTimeImmutable('2026-09-10 12:00:00'));
        $this->repository()->save($payment);

        $this->assertSame($id, $payment->id());
        $this->assertSame(1, PaymentModel::where('order_id', 'order-update-in-place')->count());

        $reloaded = $this->repository()->findById($id);
        $this->assertSame(PaymentStatus::CAPTURED, $reloaded->status());
        $this->assertSame('ch_999', $reloaded->providerReference());
    }
}
