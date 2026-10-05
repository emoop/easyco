<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentReceipt;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Refunds R4a-1 (shipping-domain-design.md §7.2.20 §2): the receipt repository — append, find, the
 * EFFECTIVE list and sum (a receipt is effective unless a later row supersedes it), the row count that
 * counts superseded ones — and the payment mapping of an accepted settlement.
 */
class PaymentReceiptRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): PaymentReceiptRepository
    {
        return app(PaymentReceiptRepository::class);
    }

    private function payment(int $expected = 10000): Payment
    {
        $payment = Payment::create('order-'.uniqid(), 'bank_transfer', Money::fromMinorUnits($expected, 'EUR'), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-10-01 09:00:00'));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    private function append(Payment $payment, int $minor, string $reference = 'REF', ?PaymentReceipt $supersedes = null, string $day = '2026-10-02'): PaymentReceipt
    {
        $receipt = PaymentReceipt::create(
            (string) $payment->id(),
            Money::fromMinorUnits($minor, 'EUR'),
            $day,
            $reference,
            new DateTimeImmutable('2026-10-02 10:00:00'),
            '3',
            $supersedes?->id(),
        );
        $this->repository()->save($receipt);

        return $receipt;
    }

    public function test_a_receipt_round_trips_with_its_day_unshifted(): void
    {
        $payment = $this->payment();
        $saved = $this->append($payment, 4000, 'BG-REF-77', day: '2026-10-02');

        $this->assertNotNull($saved->id(), 'the id is assigned on append');

        $read = $this->repository()->findById($saved->id());

        $this->assertSame((string) $payment->id(), $read->paymentId());
        $this->assertSame(4000, $read->amount()->minorValue());
        $this->assertSame('EUR', $read->amount()->currency()->code());
        $this->assertSame('2026-10-02', $read->receivedOn(), 'a calendar day comes back as the same day');
        $this->assertSame('BG-REF-77', $read->bankReference());
        $this->assertSame('3', $read->recordedBy());
        $this->assertNull($read->supersedesReceiptId());
        $this->assertSame('2026-10-02 10:00:00', $read->recordedAt()->format('Y-m-d H:i:s'));
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_the_repository_is_append_only(): void
    {
        $saved = $this->append($this->payment(), 4000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->repository()->save($saved);
    }

    public function test_the_effective_list_and_sum_cover_every_receipt_when_none_is_superseded(): void
    {
        $payment = $this->payment();
        $a = $this->append($payment, 6000, 'A');
        $b = $this->append($payment, 4000, 'B');

        $effective = $this->repository()->findEffectiveByPaymentId((string) $payment->id());

        $this->assertSame([$a->id(), $b->id()], array_map(fn (PaymentReceipt $r) => $r->id(), $effective), 'oldest first');
        $this->assertSame(10000, $this->repository()->effectiveSum((string) $payment->id(), 'EUR')->minorValue());
        $this->assertSame(2, $this->repository()->countRows((string) $payment->id()));
    }

    public function test_the_effective_list_and_sum_ignore_superseded_rows_but_the_count_does_not(): void
    {
        $payment = $this->payment();
        $first = $this->append($payment, 6000, 'TYPO');
        $second = $this->append($payment, 3000, 'OTHER PART');
        $corrected = $this->append($payment, 4000, 'FIXED', supersedes: $first);

        $effective = $this->repository()->findEffectiveByPaymentId((string) $payment->id());

        $this->assertSame([$second->id(), $corrected->id()], array_map(fn (PaymentReceipt $r) => $r->id(), $effective), 'the superseded receipt is gone from the list');
        $this->assertSame(7000, $this->repository()->effectiveSum((string) $payment->id(), 'EUR')->minorValue(), '3000 + 4000, not 6000 + 3000 + 4000');
        $this->assertSame(3, $this->repository()->countRows((string) $payment->id()), 'the row count keeps the superseded one');
    }

    public function test_a_chain_of_corrections_leaves_only_its_last_row_effective(): void
    {
        $payment = $this->payment();
        $one = $this->append($payment, 5000, 'ONE');
        $two = $this->append($payment, 5500, 'TWO', supersedes: $one);
        $three = $this->append($payment, 5600, 'THREE', supersedes: $two);

        $effective = $this->repository()->findEffectiveByPaymentId((string) $payment->id());

        $this->assertCount(1, $effective);
        $this->assertSame($three->id(), $effective[0]->id());
        $this->assertSame(5600, $this->repository()->effectiveSum((string) $payment->id(), 'EUR')->minorValue());
        $this->assertSame(3, $this->repository()->countRows((string) $payment->id()));
    }

    public function test_a_payment_without_receipts_has_an_empty_list_a_zero_sum_and_no_rows(): void
    {
        $payment = $this->payment();

        $this->assertSame([], $this->repository()->findEffectiveByPaymentId((string) $payment->id()));
        $this->assertTrue($this->repository()->effectiveSum((string) $payment->id(), 'EUR')->isZero());
        $this->assertSame(0, $this->repository()->countRows((string) $payment->id()));
    }

    public function test_other_payments_receipts_are_never_counted(): void
    {
        $mine = $this->payment();
        $theirs = $this->payment();
        $this->append($mine, 1000);
        $this->append($theirs, 9000);
        $this->append($theirs, 800);

        $this->assertSame(1000, $this->repository()->effectiveSum((string) $mine->id(), 'EUR')->minorValue());
        $this->assertCount(1, $this->repository()->findEffectiveByPaymentId((string) $mine->id()));
        $this->assertSame(1, $this->repository()->countRows((string) $mine->id()));
    }

    public function test_the_effective_sum_is_one_read(): void
    {
        $payment = $this->payment();
        $this->append($payment, 1000);
        $this->append($payment, 2000);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->repository()->effectiveSum((string) $payment->id(), 'EUR');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('sum(', strtolower($queries[0]['query']));
    }

    public function test_an_accepted_settlement_round_trips_through_the_payment_repository(): void
    {
        $payment = $this->payment(10000);
        $payment->confirm(new DateTimeImmutable('2026-10-02 10:00:00'), Money::fromMinorUnits(9000, 'EUR'), 'agreed by phone');
        app(PaymentRepository::class)->save($payment);

        $read = app(PaymentRepository::class)->findById((string) $payment->id());

        $this->assertTrue($read->isSettled());
        $this->assertSame(9000, $read->settledAmount()->minorValue());
        $this->assertSame('agreed by phone', $read->settlementReason());
        $this->assertSame(10000, $read->amount()->minorValue(), 'the expected amount is untouched');
        $this->assertSame(9000, (int) DB::table('payments')->where('id', $payment->id())->value('settled_amount_minor'));
    }

    public function test_an_ordinary_confirmation_leaves_both_columns_null_and_settles_for_the_amount(): void
    {
        $payment = $this->payment(10000);
        $payment->confirm(new DateTimeImmutable('2026-10-02 10:00:00'));
        app(PaymentRepository::class)->save($payment);

        $row = DB::table('payments')->where('id', $payment->id())->first();
        $this->assertNull($row->settled_amount_minor);
        $this->assertNull($row->settlement_reason);
        $this->assertSame(10000, app(PaymentRepository::class)->findById((string) $payment->id())->settledAmount()->minorValue());
    }
}
