<?php

namespace EasyCo\OperationalSales\Tests;

use DateTimeImmutable;
use EasyCo\OperationalSales\Enums\InstallmentPlanStatus;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Exceptions\ClientMismatchException;
use EasyCo\OperationalSales\Exceptions\CurrencyMismatchException;
use EasyCo\OperationalSales\Exceptions\InstallmentPlanNotActiveException;
use EasyCo\OperationalSales\Exceptions\OverpaymentException;
use EasyCo\OperationalSales\InstallmentPlan;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use PHPUnit\Framework\TestCase;

final class InstallmentPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Standalone tests never boot Laravel/PricingServiceProvider, so
        // EasyCo\Pricing\DefaultCurrency is never configured by app
        // wiring here — set it explicitly to match this suite's default
        // test currency (EUR), same as the host app's config default.
        DefaultCurrency::set(Currency::EUR());
    }

    protected function tearDown(): void
    {
        // DefaultCurrency is plain static state shared across every test
        // in this process — reset it so a test that reconfigures it
        // (see the "not hardcoded to a single currency" test below)
        // never leaks its choice into a later, unrelated test.
        DefaultCurrency::reset();

        parent::tearDown();
    }

    private function money(int $minorUnits, string $currency = 'EUR'): Money
    {
        return Money::fromMinorUnits($minorUnits, $currency);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-25 10:00:00');
    }

    private function reservedLine(
        string $clientId = 'client-1',
        int $amountMinorUnits = 1000,
        string $currency = 'EUR',
        ?DateTimeImmutable $effectiveAt = null,
        ?string $id = null,
    ): SaleLine {
        $line = new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: 'priceable-1',
            type: SaleLineType::RESERVATION,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money($amountMinorUnits, $currency),
            profit: $this->money((int) round($amountMinorUnits * 0.2), $currency),
            recordedAt: $this->now(),
            effectiveAt: $effectiveAt ?? $this->now(),
        );

        if ($id !== null) {
            $line->assignId($id);
        }

        return $line;
    }

    private function paymentLine(string $clientId = 'client-1', int $amountMinorUnits = 500, string $currency = 'EUR'): SaleLine
    {
        return new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: null,
            type: SaleLineType::INSTALLMENT_PAYMENT,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money($amountMinorUnits, $currency),
            profit: $this->money(0, $currency),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );
    }

    public function test_open_creates_an_active_plan_with_zero_outstanding_balance(): void
    {
        // setUp() configures EasyCo\Pricing\DefaultCurrency to EUR for
        // this suite; an empty, just-opened plan has no line of its own
        // to derive a currency from yet, so outstandingBalance() falls
        // back to that configured default (see InstallmentPlan::
        // outstandingBalance()'s docblock).
        $plan = InstallmentPlan::open('client-1');

        $this->assertNull($plan->id());
        $this->assertSame('client-1', $plan->clientId());
        $this->assertSame(InstallmentPlanStatus::ACTIVE, $plan->status());
        $this->assertSame([], $plan->reservedLines());
        $this->assertSame([], $plan->paymentLines());
        $this->assertTrue($plan->outstandingBalance()->isZero());
        $this->assertSame('EUR', $plan->outstandingBalance()->currency()->code());
    }

    public function test_empty_plan_outstanding_balance_is_not_hardcoded_to_a_single_currency(): void
    {
        // Regression test for the bug this fix closes: InstallmentPlan
        // previously hardcoded BGN as the empty-plan currency fallback,
        // which stopped being legal tender in Bulgaria on 2026-02-01.
        // Reconfiguring EasyCo\Pricing\DefaultCurrency to a currency
        // other than the suite's usual EUR default and confirming an
        // empty plan picks it up proves the fallback genuinely reads
        // from host-application configuration, not from any currency
        // hardcoded in InstallmentPlan itself.
        DefaultCurrency::set(Currency::GBP());

        $plan = InstallmentPlan::open('client-1');

        $this->assertTrue($plan->outstandingBalance()->isZero());
        $this->assertSame('GBP', $plan->outstandingBalance()->currency()->code());
    }

    public function test_empty_plan_outstanding_balance_throws_if_no_default_currency_is_configured(): void
    {
        // Fails loud rather than silently guessing a currency — the
        // exact failure mode a hardcoded fallback (BGN, or any other
        // single currency) would have papered over.
        DefaultCurrency::reset();

        $plan = InstallmentPlan::open('client-1');

        $this->expectException(\LogicException::class);
        $plan->outstandingBalance();
    }

    public function test_attach_reserved_line_rejects_a_non_reservation_line_type(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $saleLine = new SaleLine(
            id: null,
            transactionId: '',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(1000),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $plan->attachReservedLine($saleLine);
    }

    public function test_attach_reserved_line_rejects_a_mismatched_client_id(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $line = $this->reservedLine(clientId: 'client-2');

        $this->expectException(ClientMismatchException::class);
        $plan->attachReservedLine($line);
    }

    public function test_attach_reserved_line_rejects_a_mismatched_currency(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $plan->attachReservedLine($this->reservedLine(currency: 'EUR'));

        $mismatched = $this->reservedLine(currency: 'USD');

        $this->expectException(CurrencyMismatchException::class);
        $plan->attachReservedLine($mismatched);
    }

    public function test_attach_reserved_line_is_rejected_on_a_cancelled_plan(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $plan->cancel();

        $this->expectException(InstallmentPlanNotActiveException::class);
        $plan->attachReservedLine($this->reservedLine());
    }

    public function test_attach_reserved_line_is_rejected_on_a_completed_plan(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $plan->attachReservedLine($this->reservedLine(amountMinorUnits: 1000));
        $plan->recordPayment($this->paymentLine(amountMinorUnits: 1000));
        $this->assertSame(InstallmentPlanStatus::COMPLETED, $plan->status());

        $this->expectException(InstallmentPlanNotActiveException::class);
        $plan->attachReservedLine($this->reservedLine());
    }

    public function test_attaching_a_second_reserved_line_mid_plan_works_direct_regression_for_source_system_marker_bug(): void
    {
        // Direct regression test for operational-sales-domain-design.md
        // §3.3: the source system's random marker-string approach
        // silently failed to group in a NEW reserved item added while a
        // plan was already active (the marker-assignment code only ran
        // when no marker existed yet for that client at all). Here,
        // attachReservedLine() must work identically the second time as
        // the first, because it appends a real object reference, not a
        // regenerated string that has to happen to match a prior one.
        $plan = InstallmentPlan::open('client-1');

        $firstReserved = $this->reservedLine(amountMinorUnits: 1000);
        $plan->attachReservedLine($firstReserved);

        $plan->recordPayment($this->paymentLine(amountMinorUnits: 400));
        $this->assertSame(InstallmentPlanStatus::ACTIVE, $plan->status());

        $secondReserved = $this->reservedLine(amountMinorUnits: 500);
        $plan->attachReservedLine($secondReserved);

        // 1000 + 500 - 400 = 1100 — the second reservation must be
        // correctly included, unlike the source system's bug.
        $this->assertTrue($plan->outstandingBalance()->equals($this->money(1100)));
        $this->assertCount(2, $plan->reservedLines());
    }

    public function test_record_payment_rejects_a_non_installment_payment_line_type(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $plan->attachReservedLine($this->reservedLine(amountMinorUnits: 1000));

        $this->expectException(\InvalidArgumentException::class);
        $plan->recordPayment($this->reservedLine());
    }

    public function test_record_payment_exceeding_the_outstanding_balance_throws(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $plan->attachReservedLine($this->reservedLine(amountMinorUnits: 1000));

        $this->expectException(OverpaymentException::class);
        $plan->recordPayment($this->paymentLine(amountMinorUnits: 1500));
    }

    public function test_record_payment_that_exactly_zeroes_the_balance_completes_the_plan_and_returns_settlement_lines(): void
    {
        $plan = InstallmentPlan::open('client-1');

        $originalEffectiveAt = new DateTimeImmutable('2020-01-01 00:00:00');
        $reserved = $this->reservedLine(amountMinorUnits: 1000, effectiveAt: $originalEffectiveAt, id: 'reserved-line-1');
        $plan->attachReservedLine($reserved);

        $settlementLines = $plan->recordPayment($this->paymentLine(amountMinorUnits: 1000));

        $this->assertSame(InstallmentPlanStatus::COMPLETED, $plan->status());
        $this->assertCount(1, $settlementLines);

        $settlement = $settlementLines[0];
        $this->assertNull($settlement->id());
        $this->assertSame(SaleLineType::SALE, $settlement->type());
        $this->assertSame('client-1', $settlement->clientId());
        $this->assertSame('priceable-1', $settlement->priceableId());
        $this->assertSame('reserved-line-1', $settlement->originatingReservationLineId());
        $this->assertTrue($settlement->amount()->equals($this->money(1000)));
        // Preserves the ORIGINAL reservation's effectiveAt (§3.5) — must
        // NOT be "now"/recordedAt.
        $this->assertEquals($originalEffectiveAt, $settlement->effectiveAt());
        $this->assertNotEquals($originalEffectiveAt, $settlement->recordedAt());
    }

    public function test_record_payment_that_only_partially_reduces_the_balance_leaves_the_plan_active(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $plan->attachReservedLine($this->reservedLine(amountMinorUnits: 1000));

        $result = $plan->recordPayment($this->paymentLine(amountMinorUnits: 400));

        $this->assertSame([], $result);
        $this->assertSame(InstallmentPlanStatus::ACTIVE, $plan->status());
        $this->assertTrue($plan->outstandingBalance()->equals($this->money(600)));
    }

    public function test_three_payments_that_do_not_sum_cleanly_in_float_still_complete_the_plan_exactly(): void
    {
        // Direct regression test for design doc §3.1: the source
        // system's settlement check was
        // round($total_debt - $sum, 2) == 0 — exact floating-point
        // equality. 0.1 + 0.1 + 0.1 famously does NOT equal 0.3 under
        // raw binary float arithmetic (sanity-checked below), but
        // 10 + 10 + 10 equals 30 exactly as integers, because Money
        // stores minor units as integers, never floats.
        $this->assertTrue(
            0.1 + 0.1 + 0.1 !== 0.3,
            'Sanity check failed: expected these floats to demonstrate binary drift.'
        );

        $plan = InstallmentPlan::open('client-1');
        $plan->attachReservedLine($this->reservedLine(amountMinorUnits: 30));

        $result1 = $plan->recordPayment($this->paymentLine(amountMinorUnits: 10));
        $result2 = $plan->recordPayment($this->paymentLine(amountMinorUnits: 10));
        $result3 = $plan->recordPayment($this->paymentLine(amountMinorUnits: 10));

        $this->assertSame([], $result1);
        $this->assertSame([], $result2);
        $this->assertCount(1, $result3);
        $this->assertSame(InstallmentPlanStatus::COMPLETED, $plan->status());
        $this->assertTrue($plan->outstandingBalance()->isZero());
    }

    public function test_cancel_transitions_active_to_cancelled(): void
    {
        $plan = InstallmentPlan::open('client-1');

        $plan->cancel();

        $this->assertSame(InstallmentPlanStatus::CANCELLED, $plan->status());
    }

    public function test_cancel_called_a_second_time_throws(): void
    {
        $plan = InstallmentPlan::open('client-1');
        $plan->cancel();

        $this->expectException(InstallmentPlanNotActiveException::class);
        $plan->cancel();
    }

    public function test_reconstitute_from_storage_round_trips_correctly(): void
    {
        $reserved = $this->reservedLine(amountMinorUnits: 1000, id: 'reserved-1');
        $payment = $this->paymentLine(amountMinorUnits: 400);
        $payment->assignId('payment-1');

        $plan = InstallmentPlan::reconstituteFromStorage(
            id: 'plan-1',
            clientId: 'client-1',
            status: InstallmentPlanStatus::ACTIVE,
            reservedLines: [$reserved],
            paymentLines: [$payment],
        );

        $this->assertSame('plan-1', $plan->id());
        $this->assertSame('client-1', $plan->clientId());
        $this->assertSame(InstallmentPlanStatus::ACTIVE, $plan->status());
        $this->assertSame([$reserved], $plan->reservedLines());
        $this->assertSame([$payment], $plan->paymentLines());
        $this->assertTrue($plan->outstandingBalance()->equals($this->money(600)));
    }

    public function test_reconstitute_from_storage_rejects_lines_with_mismatched_currencies(): void
    {
        $reserved = $this->reservedLine(currency: 'EUR');
        $payment = $this->paymentLine(currency: 'USD');

        $this->expectException(CurrencyMismatchException::class);
        InstallmentPlan::reconstituteFromStorage(
            id: 'plan-1',
            clientId: 'client-1',
            status: InstallmentPlanStatus::ACTIVE,
            reservedLines: [$reserved],
            paymentLines: [$payment],
        );
    }
}
