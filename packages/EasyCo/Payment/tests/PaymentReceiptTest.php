<?php

namespace EasyCo\Payment\Tests;

use DateTimeImmutable;
use EasyCo\Payment\PaymentReceipt;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Refunds R4a-1 (shipping-domain-design.md §7.2.20 §2): the receipt value object. */
final class PaymentReceiptTest extends TestCase
{
    private function receipt(int $minor = 4000, string $day = '2026-10-02', string $reference = 'BG12 REF 001'): PaymentReceipt
    {
        return PaymentReceipt::create('5', Money::fromMinorUnits($minor, 'EUR'), $day, $reference, new DateTimeImmutable('2026-10-02 10:00:00'), 'staff-3');
    }

    public function test_it_carries_what_the_merchant_saw(): void
    {
        $receipt = $this->receipt();

        $this->assertNull($receipt->id());
        $this->assertSame('5', $receipt->paymentId());
        $this->assertSame(4000, $receipt->amount()->minorValue());
        $this->assertSame('2026-10-02', $receipt->receivedOn(), 'a calendar day, not an instant');
        $this->assertSame('BG12 REF 001', $receipt->bankReference());
        $this->assertNull($receipt->supersedesReceiptId());
        $this->assertSame('staff-3', $receipt->recordedBy());
        $this->assertSame('2026-10-02 10:00:00', $receipt->recordedAt()->format('Y-m-d H:i:s'));
    }

    public function test_the_reference_is_trimmed_and_must_be_1_to_64_characters(): void
    {
        $this->assertSame('REF', $this->receipt(reference: '  REF  ')->bankReference());
        $this->assertSame(64, mb_strlen($this->receipt(reference: str_repeat('x', 64))->bankReference()));

        foreach (['', '   ', str_repeat('x', 65)] as $bad) {
            try {
                $this->receipt(reference: $bad);
                $this->fail('"'.$bad.'" is not a bank reference.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('bankReference', $exception->getMessage());
            }
        }
    }

    public function test_the_amount_must_be_positive(): void
    {
        foreach ([0, -1] as $minor) {
            try {
                $this->receipt(minor: $minor);
                $this->fail('a receipt of '.$minor.' is not a receipt.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('positive', $exception->getMessage());
            }
        }
    }

    public function test_the_day_must_be_a_real_calendar_day_written_y_m_d(): void
    {
        foreach (['2026-02-30', '2026-9-1', '29/09/2026', '2026-09-29 10:00:00', 'today', ''] as $bad) {
            try {
                $this->receipt(day: $bad);
                $this->fail('"'.$bad.'" is not a calendar day.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('receivedOn', $exception->getMessage());
            }
        }
    }

    public function test_the_id_is_assigned_once(): void
    {
        $receipt = $this->receipt();
        $receipt->assignId('11');
        $this->assertSame('11', $receipt->id());

        $this->expectException(LogicException::class);
        $receipt->assignId('12');
    }

    public function test_a_correction_names_the_receipt_it_supersedes(): void
    {
        $correction = PaymentReceipt::create('5', Money::fromMinorUnits(4000, 'EUR'), '2026-10-02', 'FIXED REF', new DateTimeImmutable('2026-10-03'), null, '11');

        $this->assertSame('11', $correction->supersedesReceiptId());
        $this->assertNull($correction->recordedBy());
    }
}
