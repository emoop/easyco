<?php

namespace EasyCo\Payment\Tests;

use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\RefundBreakdown;
use EasyCo\Payment\RefundLine;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RefundBreakdownTest extends TestCase
{
    private function m(int $minor, string $currency = 'EUR'): Money
    {
        return Money::fromMinorUnits($minor, $currency);
    }

    private function refuses(callable $build, string $expected): void
    {
        try {
            $build();
            $this->fail("expected a refusal containing \"{$expected}\".");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function test_the_total_is_goods_plus_shipping_plus_adjustment_minus_deduction(): void
    {
        $breakdown = new RefundBreakdown($this->m(1000), $this->m(250), $this->m(50), $this->m(300), 'restocking');

        $this->assertSame(1000, $breakdown->total()->minorValue());
    }

    public function test_a_part_may_not_be_negative(): void
    {
        $this->refuses(fn () => new RefundBreakdown($this->m(-1), $this->m(0), $this->m(0), $this->m(0)), 'goods must not be negative');
        $this->refuses(fn () => new RefundBreakdown($this->m(100), $this->m(-1), $this->m(0), $this->m(0)), 'shipping must not be negative');
        $this->refuses(fn () => new RefundBreakdown($this->m(100), $this->m(0), $this->m(-1), $this->m(0)), 'adjustment must not be negative');
        $this->refuses(fn () => new RefundBreakdown($this->m(100), $this->m(0), $this->m(0), $this->m(-1)), 'deduction must not be negative');
    }

    public function test_the_parts_share_one_currency(): void
    {
        $this->refuses(fn () => new RefundBreakdown($this->m(100), $this->m(5, 'USD'), $this->m(0), $this->m(0)), 'different currency');
    }

    public function test_a_deduction_requires_a_reason_and_appears_on_no_line(): void
    {
        $this->refuses(fn () => new RefundBreakdown($this->m(1000), $this->m(0), $this->m(0), $this->m(100)), 'deduction requires a reason');
        $this->refuses(fn () => new RefundBreakdown($this->m(1000), $this->m(0), $this->m(0), $this->m(100), '   '), 'deduction requires a reason');

        $breakdown = new RefundBreakdown($this->m(1000), $this->m(0), $this->m(0), $this->m(100), 'damaged on return', [new RefundLine('7', $this->m(1000))]);

        $this->assertSame(900, $breakdown->total()->minorValue());
        $this->assertSame(1000, $breakdown->lines[0]->amount->minorValue(), 'the line keeps the goods amount; the deduction is refund-level');
    }

    public function test_a_deduction_larger_than_everything_else_would_make_the_total_negative_and_is_refused(): void
    {
        $this->refuses(fn () => new RefundBreakdown($this->m(100), $this->m(50), $this->m(0), $this->m(151), 'too much'), 'would be negative');
    }

    public function test_the_lines_must_add_up_to_the_goods_component(): void
    {
        $this->refuses(
            fn () => new RefundBreakdown($this->m(1000), $this->m(0), $this->m(0), $this->m(0), null, [new RefundLine('1', $this->m(400)), new RefundLine('2', $this->m(500))]),
            'add up to 900',
        );

        $ok = new RefundBreakdown($this->m(900), $this->m(0), $this->m(0), $this->m(0), null, [new RefundLine('1', $this->m(400)), new RefundLine('2', $this->m(500))]);
        $this->assertCount(2, $ok->lines);
    }

    public function test_a_line_may_carry_zero_but_never_a_negative_amount_and_never_twice(): void
    {
        $zero = new RefundBreakdown($this->m(500), $this->m(0), $this->m(0), $this->m(0), null, [new RefundLine('1', $this->m(500)), new RefundLine('2', $this->m(0))]);
        $this->assertSame(0, $zero->lines[1]->amount->minorValue());

        $this->refuses(fn () => new RefundLine('1', $this->m(-1)), 'must not be negative');
        $this->refuses(fn () => new RefundLine(' ', $this->m(1)), 'saleLineId');
        $this->refuses(
            fn () => new RefundBreakdown($this->m(10), $this->m(0), $this->m(0), $this->m(0), null, [new RefundLine('1', $this->m(5)), new RefundLine('1', $this->m(5))]),
            'more than once',
        );
    }

    public function test_goods_only_is_a_plain_amount(): void
    {
        $breakdown = RefundBreakdown::goodsOnly($this->m(321));

        $this->assertSame(321, $breakdown->goods->minorValue());
        $this->assertSame(321, $breakdown->total()->minorValue());
        $this->assertSame([], $breakdown->lines);
    }

    // --- the state set and the channel -------------------------------------------------------

    public function test_the_counting_states_are_everything_whose_money_is_spoken_for(): void
    {
        $this->assertEqualsCanonicalizing(
            [PaymentRefundStatus::OWED, PaymentRefundStatus::PAID_OUT, PaymentRefundStatus::REQUESTED, PaymentRefundStatus::COMPLETED],
            PaymentRefundStatus::counting(),
        );
        $this->assertFalse(PaymentRefundStatus::CANCELLED->counts());
        $this->assertFalse(PaymentRefundStatus::FAILED->counts());
        $this->assertTrue(PaymentRefundStatus::OWED->counts());
    }

    public function test_the_state_values_are_the_stored_strings(): void
    {
        $this->assertSame(
            ['owed', 'paid_out', 'cancelled', 'requested', 'completed', 'failed'],
            array_map(static fn (PaymentRefundStatus $s): string => $s->value, PaymentRefundStatus::cases()),
        );
    }

    public function test_the_default_channel_follows_the_payment_method(): void
    {
        $this->assertSame(RefundChannel::CASH, RefundChannel::defaultForMethod('cash_on_delivery'));
        $this->assertSame(RefundChannel::BANK, RefundChannel::defaultForMethod('bank_transfer'));
        $this->assertSame(RefundChannel::BANK, RefundChannel::defaultForMethod('anything_else'));
    }
}
