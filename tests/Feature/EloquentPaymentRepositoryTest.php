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
use Illuminate\Support\Str;
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

    // --- the second settled constraint, and the two new facts (stage 5) -------

    /**
     * order-lifecycle-design.md §4.4's `settled_order_id`, asserted against the
     * REAL DDL — the same standard the test above applies to
     * captured_order_id, and for the same reason: a migration file states what
     * was intended, the engine states what is enforced.
     *
     * The two things this test is really pinning, beyond "the objects exist":
     * captured_order_id was NOT widened to cover the offline half (§4.4's
     * explicitly rejected alternative), and neither generated column looks at
     * voided_at — which is what makes "a voided row keeps contributing NULL to
     * both" a property of the schema and not a coincidence of the write path.
     */
    public function test_the_real_settled_order_id_generated_column_and_its_unique_index(): void
    {
        $createTable = DB::select('SHOW CREATE TABLE payments')[0]->{'Create Table'};

        // confirmed_at/voided_at: nullable timestamps, in the order the
        // migrations put them in (after attempted_at) — which is also the order
        // Payment's own constructor takes them in.
        $this->assertStringContainsString(
            "`attempted_at` timestamp NULL DEFAULT NULL,\n"
            ."  `confirmed_at` timestamp NULL DEFAULT NULL,\n"
            .'  `voided_at` timestamp NULL DEFAULT NULL,',
            $createTable
        );

        $this->assertStringContainsString('`settled_order_id`', $createTable);
        $this->assertStringContainsString('GENERATED ALWAYS AS', $createTable);
        $this->assertStringContainsString('STORED', $createTable);
        $this->assertStringContainsString(
            "case when ((`status` = _utf8mb4'captured') or (`confirmed_at` is not null)) then `order_id` else NULL end",
            $createTable
        );
        $this->assertStringContainsString('UNIQUE KEY `pay_settled_order_unique` (`settled_order_id`)', $createTable);

        // The existing pair is untouched: still there, still its own name, and
        // its expression still says nothing about confirmed_at.
        $this->assertStringContainsString('UNIQUE KEY `pay_captured_order_unique` (`captured_order_id`)', $createTable);

        $capturedExpression = Str::before(Str::after($createTable, '`captured_order_id`'), 'STORED');
        $settledExpression = Str::before(Str::after($createTable, '`settled_order_id`'), 'STORED');

        $this->assertStringContainsString("case when (`status` = _utf8mb4'captured') then `order_id` else NULL end", $capturedExpression);
        $this->assertStringNotContainsString('confirmed_at', $capturedExpression);
        $this->assertStringNotContainsString('voided_at', $capturedExpression);
        $this->assertStringNotContainsString('voided_at', $settledExpression);

        // Generated columns are computed by MySQL and never filled from PHP, so
        // neither may be fillable — while the two real new columns are.
        $fillable = (new PaymentModel)->getFillable();
        $this->assertContains('confirmed_at', $fillable);
        $this->assertContains('voided_at', $fillable);
        $this->assertNotContains('settled_order_id', $fillable);
        $this->assertNotContains('captured_order_id', $fillable);

        // MySQL/MariaDB identifiers stop at 64 characters (CLAUDE.md rule 5).
        // Asserted over the real index names rather than over a literal this
        // test itself wrote.
        foreach (DB::select('SHOW INDEX FROM payments') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index->Key_name), "index name \"{$index->Key_name}\" exceeds MySQL's identifier limit");
        }
    }

    /**
     * The engine, not an application check: two settled rows for one order
     * cannot both exist. Asserted through the SQLSTATE + driver error code
     * (CLAUDE.md rule 3) rather than by matching an exception message, because
     * the message is the one part of a QueryException that is allowed to
     * change.
     *
     * The courtesy check OrderPaymentConfirmer performs in front of this is a
     * different thing entirely (order-lifecycle-design.md §4.3 step 2): it
     * produces a readable refusal for an operator, and this constraint is what
     * makes the rule true when two requests race past it.
     */
    public function test_the_engine_refuses_a_second_confirmed_payment_for_the_same_order(): void
    {
        $first = $this->confirmedPayment('order-double-settle');
        $this->repository()->save($first);

        $second = Payment::create('order-double-settle', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $second->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 11:00:00'));
        $second->confirm(new DateTimeImmutable('2026-09-28 11:05:00'));

        try {
            $this->repository()->save($second);
            $this->fail('A second settled payment for one order must be refused by the database engine.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertSame(1062, $exception->errorInfo[1]);
        }

        $this->assertSame(1, PaymentModel::where('order_id', 'order-double-settle')->count());
    }

    /**
     * The two halves of §4.4's rule settle the same order, so a captured row
     * and a confirmed row cannot coexist either — asserted in BOTH orders,
     * because the constraint has to refuse whichever one arrives second.
     */
    public function test_the_engine_refuses_a_captured_and_a_confirmed_payment_for_the_same_order(): void
    {
        $captured = Payment::create('order-captured-then-confirmed', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $captured->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_1', null, new DateTimeImmutable('2026-09-28 12:00:00'));
        $this->repository()->save($captured);

        $confirmed = Payment::create('order-captured-then-confirmed', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $confirmed->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 12:05:00'));
        $confirmed->confirm(new DateTimeImmutable('2026-09-28 12:10:00'));

        try {
            $this->repository()->save($confirmed);
            $this->fail('A confirmation must not be able to settle an order that is already captured.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertSame(1062, $exception->errorInfo[1]);
        }

        $confirmedFirst = $this->confirmedPayment('order-confirmed-then-captured');
        $this->repository()->save($confirmedFirst);

        $capturedSecond = Payment::create('order-confirmed-then-captured', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $capturedSecond->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_2', null, new DateTimeImmutable('2026-09-28 13:00:00'));

        try {
            $this->repository()->save($capturedSecond);
            $this->fail('A capture must not be able to settle an order whose money is already recorded as received.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertSame(1062, $exception->errorInfo[1]);
        }
    }

    /**
     * Everything that is NOT settled coexists freely: unanswered and answered
     * pending rows, failed rows and voided rows — any number of them, for one
     * order. MySQL treats multiple NULLs in a unique index as non-conflicting,
     * which is the entire mechanism both constraints use.
     */
    public function test_any_number_of_pending_failed_and_voided_rows_for_one_order_save_without_conflict(): void
    {
        $unanswered = Payment::create('order-many-attempts', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $this->repository()->save($unanswered);

        $pending = Payment::create('order-many-attempts', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $pending->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 14:00:00'));
        $this->repository()->save($pending);

        $failed = Payment::create('order-many-attempts', 'card_stripe', $this->amount(), PaymentStatus::FAILED, failureReason: 'declined');
        $failed->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 14:05:00'));
        $this->repository()->save($failed);

        $voided = Payment::create('order-many-attempts', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $voided->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 14:10:00'));
        $voided->void(new DateTimeImmutable('2026-09-28 14:20:00'));
        $this->repository()->save($voided);

        $this->assertSame(4, PaymentModel::where('order_id', 'order-many-attempts')->count());

        // ...and a NEW settled row for that same order still saves, because a
        // voided row contributes NULL to settled_order_id.
        $confirmed = $this->confirmedPayment('order-many-attempts');
        $this->repository()->save($confirmed);

        $this->assertSame(5, PaymentModel::where('order_id', 'order-many-attempts')->count());
        $this->assertSame($confirmed->id(), $this->repository()->findSettledForOrder('order-many-attempts')?->id());
    }

    /**
     * findSettledForOrder(): the settled row when there is one, null when there
     * is not, and NEVER a voided row — a called-off obligation keeps its place
     * in the trail but holds no money, so it is not what a refund may target
     * (order-lifecycle-design.md §7.3).
     */
    public function test_find_settled_for_order_returns_the_settled_row_and_never_a_voided_one(): void
    {
        // No payments at all.
        $this->assertNull($this->repository()->findSettledForOrder('order-no-payments'));

        // Attempts, but nothing settled.
        $failed = Payment::create('order-nothing-settled', 'card_stripe', $this->amount(), PaymentStatus::FAILED, failureReason: 'declined');
        $failed->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 15:00:00'));
        $this->repository()->save($failed);

        // A NEWER pending row, deliberately: "latest" and "settled" are two
        // different questions, and the failed retry must not be what comes back.
        $newerPending = Payment::create('order-nothing-settled', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $newerPending->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 15:30:00'));
        $this->repository()->save($newerPending);

        $this->assertNull($this->repository()->findSettledForOrder('order-nothing-settled'));

        // A voided row is not settled either — even though it is the newest.
        $voided = Payment::create('order-voided-only', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $voided->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 16:00:00'));
        $voided->void(new DateTimeImmutable('2026-09-28 16:30:00'));
        $this->repository()->save($voided);

        $this->assertNull($this->repository()->findSettledForOrder('order-voided-only'));

        // The confirmed offline row: this is the one it exists to find.
        $confirmed = $this->confirmedPayment('order-confirmed-offline');
        $this->repository()->save($confirmed);

        $settled = $this->repository()->findSettledForOrder('order-confirmed-offline');
        $this->assertNotNull($settled);
        $this->assertSame($confirmed->id(), $settled->id());
        $this->assertTrue($settled->isSettled());
        $this->assertNotNull($settled->confirmedAt());
        $this->assertNull($settled->voidedAt());

        // The captured one, on an order that also has an older failed attempt.
        $older = Payment::create('order-captured-with-retry', 'card_stripe', $this->amount(), PaymentStatus::FAILED, failureReason: 'declined');
        $older->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 17:00:00'));
        $this->repository()->save($older);

        $captured = Payment::create('order-captured-with-retry', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $captured->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_3', null, new DateTimeImmutable('2026-09-28 17:30:00'));
        $this->repository()->save($captured);

        $this->assertSame($captured->id(), $this->repository()->findSettledForOrder('order-captured-with-retry')?->id());
    }

    /**
     * §4.4's two expressions of one rule — the generated column, and the
     * predicate findSettledForOrder() writes out by hand — agree with each
     * other and with the domain's own isSettled(), on a mixed set of rows.
     * Three independent statements of "money is held on this row", compared row
     * by row: a change to any one of them that the others do not follow fails
     * here instead of in production.
     */
    public function test_the_explicit_settled_predicate_and_the_generated_column_agree_on_a_mixed_set(): void
    {
        $confirmed = $this->confirmedPayment('agree-order-1');
        $this->repository()->save($confirmed);

        $captured = Payment::create('agree-order-2', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $captured->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_4', null, new DateTimeImmutable('2026-09-28 18:00:00'));
        $this->repository()->save($captured);

        $voided = Payment::create('agree-order-3', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $voided->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 18:30:00'));
        $voided->void(new DateTimeImmutable('2026-09-28 18:40:00'));
        $this->repository()->save($voided);

        $failed = Payment::create('agree-order-3', 'card_stripe', $this->amount(), PaymentStatus::FAILED, failureReason: 'declined');
        $failed->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 18:45:00'));
        $this->repository()->save($failed);

        $unanswered = Payment::create('agree-order-4', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $this->repository()->save($unanswered);

        $rows = DB::table('payments')->orderBy('id')->get();
        $this->assertCount(5, $rows);

        foreach ($rows as $row) {
            $domainSaysSettled = $row->status === PaymentStatus::CAPTURED->value || $row->confirmed_at !== null;

            $this->assertSame(
                $domainSaysSettled,
                $row->settled_order_id !== null,
                "row {$row->id}: settled_order_id must mark exactly the settled rows"
            );
        }

        $orderIds = array_values(array_unique(array_map(
            static fn (object $row): string => (string) $row->order_id,
            $rows->all(),
        )));

        foreach ($orderIds as $orderId) {
            $flaggedIds = array_map('strval', DB::table('payments')
                ->where('order_id', $orderId)
                ->whereNotNull('settled_order_id')
                ->pluck('id')
                ->all());

            $found = $this->repository()->findSettledForOrder($orderId);

            $this->assertSame(
                $flaggedIds,
                $found !== null ? [(string) $found->id()] : [],
                "order {$orderId}: the explicit predicate must find the same row the generated column flags"
            );
        }
    }

    public function test_confirmed_at_and_voided_at_round_trip_through_the_repository(): void
    {
        $confirmed = $this->confirmedPayment('order-round-trip-confirmed', '2026-09-28 10:30:00');
        $this->repository()->save($confirmed);

        $reloaded = $this->repository()->findById($confirmed->id());
        $this->assertInstanceOf(DateTimeImmutable::class, $reloaded->confirmedAt());
        $this->assertSame('2026-09-28 10:30:00', $reloaded->confirmedAt()->format('Y-m-d H:i:s'));
        $this->assertNull($reloaded->voidedAt());
        $this->assertTrue($reloaded->isSettled());

        $voided = Payment::create('order-round-trip-voided', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);
        $voided->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 19:00:00'));
        $voided->void(new DateTimeImmutable('2026-09-28 19:15:00'));
        $this->repository()->save($voided);

        $reloadedVoided = $this->repository()->findById($voided->id());
        $this->assertInstanceOf(DateTimeImmutable::class, $reloadedVoided->voidedAt());
        $this->assertSame('2026-09-28 19:15:00', $reloadedVoided->voidedAt()->format('Y-m-d H:i:s'));
        $this->assertNull($reloadedVoided->confirmedAt());
        $this->assertTrue($reloadedVoided->isVoided());
        $this->assertFalse($reloadedVoided->isSettled());
        $this->assertSame(PaymentStatus::PENDING, $reloadedVoided->status(), 'a void never moves the status');

        $plain = Payment::create('order-round-trip-plain', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $this->repository()->save($plain);

        $reloadedPlain = $this->repository()->findById($plain->id());
        $this->assertNull($reloadedPlain->confirmedAt());
        $this->assertNull($reloadedPlain->voidedAt());
        $this->assertFalse($reloadedPlain->isVoided());
    }

    /**
     * A pending payment with an answered attempt, confirmed at a fixed instant —
     * exactly the state a merchant's "the money arrived" action leaves behind
     * for an offline method: status still pending, confirmed_at set.
     */
    private function confirmedPayment(string $orderId, string $confirmedAt = '2026-09-28 10:30:00'): Payment
    {
        $payment = Payment::create($orderId, 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 10:00:00'));
        $payment->confirm(new DateTimeImmutable($confirmedAt));

        return $payment;
    }
}
