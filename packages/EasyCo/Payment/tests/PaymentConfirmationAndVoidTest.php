<?php

namespace EasyCo\Payment\Tests;

use DateTimeImmutable;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The two one-time money facts beyond the adapter's own answer —
 * order-lifecycle-design.md §4.1's confirm()/isSettled() and §7.3's
 * void()/isVoided()/voidedAt().
 *
 * A SEPARATE FILE FROM PaymentTest, mirroring how the Order package keeps its
 * own transition mutators in OrderTransitionsTest: PaymentTest stays the
 * construction/recordAttemptResult story it has always told, and this file is
 * the new story end to end. Everything here is the DOMAIN's own behaviour —
 * no database, no Laravel, the same standalone position every other test in
 * this package takes.
 */
final class PaymentConfirmationAndVoidTest extends TestCase
{
    private function amount(int $minor = 1000): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    /**
     * The state both new mutators start from: an offline attempt that was
     * genuinely answered and is awaiting a merchant.
     */
    private function answeredPending(string $orderId = 'order-1', string $attemptedAt = '2026-09-28 10:00:00'): Payment
    {
        $payment = Payment::create($orderId, 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable($attemptedAt));

        return $payment;
    }

    // --- confirm() -----------------------------------------------------------

    public function test_confirm_records_the_instant_and_leaves_the_status_and_the_attempt_alone(): void
    {
        $payment = $this->answeredPending();
        $attemptedAt = $payment->attemptedAt();
        $confirmedAt = new DateTimeImmutable('2026-09-28 11:15:00');

        $payment->confirm($confirmedAt);

        $this->assertSame($confirmedAt, $payment->confirmedAt());
        $this->assertSame(PaymentStatus::PENDING, $payment->status(), 'a confirmation never moves the status (§4.2)');
        $this->assertSame($attemptedAt, $payment->attemptedAt());
        $this->assertSame('cash_on_delivery', $payment->method());
        $this->assertSame('order-1', $payment->orderId());
        $this->assertSame(1000, $payment->amount()->minorValue());
        $this->assertNull($payment->providerReference());
        $this->assertNull($payment->failureReason());
        $this->assertNull($payment->id());
        $this->assertNull($payment->voidedAt());
        $this->assertTrue($payment->isSettled(), 'the offline money is held once a merchant records it');
    }

    public function test_a_second_confirm_is_refused(): void
    {
        $payment = $this->answeredPending();
        $payment->confirm(new DateTimeImmutable('2026-09-28 11:15:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('one-time operation');

        $payment->confirm(new DateTimeImmutable('2026-09-28 11:20:00'));
    }

    public function test_confirm_refuses_an_attempt_that_was_never_answered(): void
    {
        // PENDING with a NULL attemptedAt — the crashed/never-answered state
        // attempted_at exists to expose.
        $payment = Payment::create('order-1', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('never answered');

        $payment->confirm(new DateTimeImmutable('2026-09-28 11:15:00'));
    }

    public function test_confirm_refuses_a_captured_payment(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_1', null, new DateTimeImmutable('2026-09-28 10:00:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already settled by the adapter');

        $payment->confirm(new DateTimeImmutable('2026-09-28 11:15:00'));
    }

    public function test_confirm_refuses_a_failed_payment(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 10:00:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('there is no money');

        $payment->confirm(new DateTimeImmutable('2026-09-28 11:15:00'));
    }

    public function test_confirm_refuses_a_voided_payment(): void
    {
        $payment = $this->answeredPending();
        $payment->void(new DateTimeImmutable('2026-09-28 10:30:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('was voided');

        $payment->confirm(new DateTimeImmutable('2026-09-28 11:15:00'));
    }

    // --- void() --------------------------------------------------------------

    public function test_void_records_the_instant_and_leaves_the_status_and_the_attempt_alone(): void
    {
        $payment = $this->answeredPending();
        $attemptedAt = $payment->attemptedAt();
        $voidedAt = new DateTimeImmutable('2026-09-28 10:30:00');

        $payment->void($voidedAt);

        $this->assertSame($voidedAt, $payment->voidedAt());
        $this->assertTrue($payment->isVoided());
        $this->assertSame(PaymentStatus::PENDING, $payment->status(), 'a void never moves the status (§7.3)');
        $this->assertSame($attemptedAt, $payment->attemptedAt());
        $this->assertNull($payment->confirmedAt());
        $this->assertFalse($payment->isSettled(), 'calling off an obligation holds no money');
    }

    public function test_a_second_void_is_refused(): void
    {
        $payment = $this->answeredPending();
        $payment->void(new DateTimeImmutable('2026-09-28 10:30:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('one-time operation');

        $payment->void(new DateTimeImmutable('2026-09-28 10:45:00'));
    }

    public function test_void_refuses_an_attempt_that_was_never_answered(): void
    {
        $payment = Payment::create('order-1', 'bank_transfer', $this->amount(), PaymentStatus::PENDING);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('never answered');

        $payment->void(new DateTimeImmutable('2026-09-28 10:30:00'));
    }

    public function test_void_refuses_a_captured_payment(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_1', null, new DateTimeImmutable('2026-09-28 10:00:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is settled');

        $payment->void(new DateTimeImmutable('2026-09-28 10:30:00'));
    }

    public function test_void_refuses_a_failed_payment(): void
    {
        $payment = Payment::create('order-1', 'card_stripe', $this->amount(), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 10:00:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no outstanding obligation');

        $payment->void(new DateTimeImmutable('2026-09-28 10:30:00'));
    }

    /**
     * An already-confirmed row is refused as SETTLED rather than as "not
     * pending" — the refusal names the reason a merchant can act on: money that
     * really moved is refunded, never called off.
     */
    public function test_void_refuses_an_already_confirmed_payment(): void
    {
        $payment = $this->answeredPending();
        $payment->confirm(new DateTimeImmutable('2026-09-28 10:15:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is settled');

        $payment->void(new DateTimeImmutable('2026-09-28 10:30:00'));
    }

    // --- isSettled(): the one predicate --------------------------------------

    /**
     * The truth table, over status × confirmed_at, built through
     * reconstituteFromStorage because that is the only way a state the WRITE
     * path refuses can exist at all — a FAILED row carrying a confirmation, for
     * instance: confirm()'s own guard refuses to create one, but a row in
     * storage is a row in storage, and isSettled() must still answer it
     * truthfully rather than throwing.
     */
    public function test_is_settled_truth_table_over_status_and_confirmation(): void
    {
        $table = [
            'pending, unconfirmed' => [PaymentStatus::PENDING, null, false],
            'pending, confirmed' => [PaymentStatus::PENDING, '2026-09-28 11:15:00', true],
            'captured, unconfirmed' => [PaymentStatus::CAPTURED, null, true],
            'captured, confirmed' => [PaymentStatus::CAPTURED, '2026-09-28 11:15:00', true],
            'failed, unconfirmed' => [PaymentStatus::FAILED, null, false],
            'failed, confirmed' => [PaymentStatus::FAILED, '2026-09-28 11:15:00', true],
        ];

        foreach ($table as $case => [$status, $confirmedAt, $expected]) {
            $this->assertSame(
                $expected,
                $this->reconstituted(status: $status, confirmedAt: $confirmedAt)->isSettled(),
                "isSettled() for {$case}"
            );
        }
    }

    /**
     * voided_at is deliberately NOT part of isSettled(): the predicate is the
     * adapter's capture or a merchant's confirmation, nothing else. So a voided
     * pending row — the only kind any write path can produce — is not settled,
     * and a void can never be read as money in hand (§7.3).
     */
    public function test_a_voided_row_is_not_settled_and_not_confirmed(): void
    {
        $payment = $this->reconstituted(status: PaymentStatus::PENDING, voidedAt: '2026-09-28 10:30:00');

        $this->assertTrue($payment->isVoided());
        $this->assertNull($payment->confirmedAt());
        $this->assertFalse($payment->isSettled());
    }

    // --- factories and reconstitution ----------------------------------------

    public function test_create_leaves_both_new_facts_null_without_the_new_arguments(): void
    {
        $payment = Payment::create('order-1', 'cash_on_delivery', $this->amount(), PaymentStatus::PENDING);

        $this->assertNull($payment->confirmedAt());
        $this->assertNull($payment->voidedAt());
        $this->assertFalse($payment->isSettled());
        $this->assertFalse($payment->isVoided());
    }

    /**
     * Both new parameters are threaded through create() like
     * providerReference/failureReason are — last and defaulted, so every call
     * site written before them keeps compiling and keeps meaning what it meant.
     */
    public function test_create_threads_already_known_facts_through_like_the_other_optional_fields(): void
    {
        $confirmedAt = new DateTimeImmutable('2026-09-28 11:15:00');

        $payment = Payment::create('order-1', 'bank_transfer', $this->amount(), PaymentStatus::PENDING, confirmedAt: $confirmedAt);

        $this->assertSame($confirmedAt, $payment->confirmedAt());
        $this->assertTrue($payment->isSettled());
        $this->assertNull($payment->voidedAt());
    }

    public function test_reconstitute_from_storage_carries_both_new_timestamps(): void
    {
        $attemptedAt = new DateTimeImmutable('2026-09-28 10:00:00');
        $voidedAt = new DateTimeImmutable('2026-09-28 10:30:00');

        $payment = Payment::reconstituteFromStorage(
            id: '42',
            orderId: 'order-1',
            method: 'bank_transfer',
            amount: $this->amount(),
            status: PaymentStatus::PENDING,
            providerReference: null,
            failureReason: null,
            attemptedAt: $attemptedAt,
            voidedAt: $voidedAt,
        );

        $this->assertSame('42', $payment->id());
        $this->assertSame($attemptedAt, $payment->attemptedAt());
        $this->assertSame($voidedAt, $payment->voidedAt());
        $this->assertNull($payment->confirmedAt());
    }

    /**
     * AN EXISTING CALL SITE, BY POSITION — every argument a pre-stage-5
     * repository passed, with no confirmedAt/voidedAt at all, still
     * reconstitutes and still means the same thing. That is the property which
     * let the two parameters land without touching a single caller.
     */
    public function test_reconstitute_from_storage_still_works_without_the_two_new_arguments(): void
    {
        $attemptedAt = new DateTimeImmutable('2026-09-28 10:00:00');

        $payment = Payment::reconstituteFromStorage(
            '42',
            'order-1',
            'cash_on_delivery',
            $this->amount(),
            PaymentStatus::PENDING,
            null,
            null,
            $attemptedAt,
        );

        $this->assertSame('42', $payment->id());
        $this->assertSame($attemptedAt, $payment->attemptedAt());
        $this->assertNull($payment->confirmedAt());
        $this->assertNull($payment->voidedAt());
    }

    /**
     * A row as storage hands it back, with the two new facts optional — the
     * shape the truth table above is built from.
     */
    private function reconstituted(
        PaymentStatus $status,
        ?string $confirmedAt = null,
        ?string $voidedAt = null,
    ): Payment {
        return Payment::reconstituteFromStorage(
            id: '1',
            orderId: 'order-1',
            method: 'bank_transfer',
            amount: $this->amount(),
            status: $status,
            providerReference: null,
            failureReason: null,
            attemptedAt: new DateTimeImmutable('2026-09-28 10:00:00'),
            confirmedAt: $confirmedAt !== null ? new DateTimeImmutable($confirmedAt) : null,
            voidedAt: $voidedAt !== null ? new DateTimeImmutable($voidedAt) : null,
        );
    }
}
