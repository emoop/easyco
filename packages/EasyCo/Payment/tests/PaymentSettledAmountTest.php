<?php

namespace EasyCo\Payment\Tests;

use DateTimeImmutable;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Refunds R4a-1 (shipping-domain-design.md §7.2.20 §2, §4): what a payment was SETTLED for.
 * `settledAmount()` is the accepted amount of a mismatch, else the expected amount; confirm()
 * takes an accepted amount and its reason TOGETHER or not at all.
 */
final class PaymentSettledAmountTest extends TestCase
{
    private function money(int $minor, string $currency = 'EUR'): Money
    {
        return Money::fromMinorUnits($minor, $currency);
    }

    private function awaitingMoney(int $expected = 10000): Payment
    {
        $payment = Payment::create('order-1', 'bank_transfer', $this->money($expected), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-10-01 09:00:00'));

        return $payment;
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-02 10:00:00');
    }

    public function test_a_payment_without_an_accepted_amount_is_settled_for_its_expected_amount(): void
    {
        $payment = $this->awaitingMoney();
        $this->assertTrue($payment->settledAmount()->equals($this->money(10000)), 'not settled yet: the expected amount');

        $payment->confirm($this->at());

        $this->assertTrue($payment->settledAmount()->equals($payment->amount()), 'an ordinary confirmation: settled for exactly the expected amount');
        $this->assertNull($payment->settlementReason());
    }

    public function test_a_captured_or_legacy_reconstituted_payment_is_settled_for_its_amount(): void
    {
        $legacy = Payment::reconstituteFromStorage('7', 'order-1', 'bank_transfer', $this->money(10000), PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-02'), null);
        $captured = Payment::reconstituteFromStorage('8', 'order-2', 'card', $this->money(2500), PaymentStatus::CAPTURED, 'ref', null, new DateTimeImmutable('2026-10-01'));

        $this->assertTrue($legacy->settledAmount()->equals($this->money(10000)));
        $this->assertTrue($captured->settledAmount()->equals($this->money(2500)));
    }

    public function test_confirm_with_an_accepted_amount_sets_both_and_settled_amount_reads_it(): void
    {
        $short = $this->awaitingMoney();
        $short->confirm($this->at(), $this->money(9000), '  customer paid 90, agreed by phone  ');

        $this->assertTrue($short->isSettled());
        $this->assertTrue($short->settledAmount()->equals($this->money(9000)));
        $this->assertSame('customer paid 90, agreed by phone', $short->settlementReason(), 'the reason is stored trimmed');
        $this->assertTrue($short->amount()->equals($this->money(10000)), 'the expected amount never changes');

        $over = $this->awaitingMoney();
        $over->confirm($this->at(), $this->money(11000), 'paid 110, surplus to be refunded');
        $this->assertTrue($over->settledAmount()->equals($this->money(11000)));
    }

    public function test_confirm_with_only_one_of_the_two_arguments_is_refused(): void
    {
        foreach ([[$this->money(9000), null], [null, 'a reason']] as [$amount, $reason]) {
            $payment = $this->awaitingMoney();

            try {
                $payment->confirm($this->at(), $amount, $reason);
                $this->fail('the accepted amount and its reason go together.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('both given or both absent', $exception->getMessage());
            }

            $this->assertFalse($payment->isSettled(), 'nothing was recorded');
            $this->assertNull($payment->confirmedAt());
        }
    }

    public function test_confirm_refuses_a_non_positive_a_different_currency_or_an_equal_accepted_amount(): void
    {
        $cases = [
            'zero' => [$this->money(0), 'positive'],
            'negative' => [$this->money(-100), 'positive'],
            'another currency' => [$this->money(9000, 'USD'), 'same currency'],
            'the expected amount itself' => [$this->money(10000), 'differ'],
        ];

        foreach ($cases as $what => [$amount, $fragment]) {
            $payment = $this->awaitingMoney();

            try {
                $payment->confirm($this->at(), $amount, 'reason');
                $this->fail("{$what} must be refused.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($fragment, $exception->getMessage(), $what);
            }

            $this->assertFalse($payment->isSettled(), $what);
        }
    }

    public function test_confirm_refuses_a_blank_or_overlong_reason(): void
    {
        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $payment = $this->awaitingMoney();

            try {
                $payment->confirm($this->at(), $this->money(9000), $reason);
                $this->fail('a reason of '.mb_strlen($reason).' characters must be refused.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('settlement reason', $exception->getMessage());
            }

            $this->assertFalse($payment->isSettled());
        }
    }

    public function test_a_reason_of_exactly_255_characters_is_accepted(): void
    {
        $payment = $this->awaitingMoney();
        $payment->confirm($this->at(), $this->money(9000), str_repeat('x', 255));

        $this->assertSame(255, mb_strlen((string) $payment->settlementReason()));
    }

    public function test_confirm_stays_one_time_and_keeps_every_existing_guard_whatever_the_arguments(): void
    {
        $payment = $this->awaitingMoney();
        $payment->confirm($this->at(), $this->money(9000), 'reason');

        try {
            $payment->confirm($this->at(), $this->money(9500), 'another reason');
            $this->fail('confirm() is one-time.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('one-time', $exception->getMessage());
        }

        $this->assertTrue($payment->settledAmount()->equals($this->money(9000)), 'the first acceptance stands');

        $captured = Payment::create('order-2', 'card', $this->money(10000), PaymentStatus::PENDING);
        $captured->recordAttemptResult(PaymentStatus::CAPTURED, 'ref', null, new DateTimeImmutable('2026-10-01'));
        $this->expectException(LogicException::class);
        $captured->confirm($this->at(), $this->money(9000), 'reason');
    }

    public function test_a_settled_amount_exists_only_on_a_confirmed_payment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('confirmed payment');

        Payment::reconstituteFromStorage('9', 'order-1', 'bank_transfer', $this->money(10000), PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-10-01'), null, null, $this->money(9000), 'reason');
    }

    public function test_a_stored_acceptance_is_reconstituted(): void
    {
        $payment = Payment::reconstituteFromStorage('9', 'order-1', 'bank_transfer', $this->money(10000), PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-02'), null, $this->money(9000), 'reason');

        $this->assertTrue($payment->settledAmount()->equals($this->money(9000)));
        $this->assertSame('reason', $payment->settlementReason());
    }
}
