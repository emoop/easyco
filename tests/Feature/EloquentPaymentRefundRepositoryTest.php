<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Payment\RefundBreakdown;
use EasyCo\Payment\RefundLine;
use EasyCo\Pricing\Money;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The refund RECORD in the database (refunds R1a): the round trip of the whole
 * breakdown, the per-line rows and their constraints, the CHECKs, and the SUM
 * that counts every refund whose money is spoken for.
 */
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

    private function refund(
        string $paymentId,
        int $minor,
        PaymentRefundStatus $status = PaymentRefundStatus::OWED,
        ?RefundBreakdown $breakdown = null,
        ?string $reason = null,
        ?string $refundedBy = null,
        ?string $failureReason = null,
    ): PaymentRefund {
        return PaymentRefund::create($paymentId, 'order-1', $this->amount($minor), $status, RefundChannel::CASH, $breakdown, $reason, $refundedBy, $failureReason);
    }

    /** @return array{0: string, 1: string} two persisted original SALE line ids */
    private function twoSaleLines(): array
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);

        foreach (['SKU-A', 'SKU-B'] as $sku) {
            $transaction->addSaleLine(SaleLine::create(
                transactionId: '',
                clientId: $client->id(),
                priceableId: 'variation-'.$sku,
                status: SaleLineStatus::COMPLETED,
                quantity: 1,
                amount: $this->amount(1000),
                profit: $this->amount(200),
                recordedAt: new DateTimeImmutable('2026-01-01'),
                effectiveAt: new DateTimeImmutable('2026-01-01'),
                productName: 'Product '.$sku,
                sku: $sku,
                regularUnitPrice: $this->amount(1000),
                finalUnitPrice: $this->amount(1000),
                promotionDiscountShare: Money::zero('EUR'),
                discretionaryDiscount: Money::zero('EUR'),
                netPaidAmount: $this->amount(1000),
                soldAttributes: [],
            ));
        }

        app(TransactionRepository::class)->save($transaction);

        $ids = array_map(static fn (SaleLine $line): string => (string) $line->id(), $transaction->saleLines());

        return [$ids[0], $ids[1]];
    }

    private function rawRefund(array $overrides = []): array
    {
        return array_merge([
            'payment_id' => 'payment-raw',
            'order_id' => 'order-1',
            'amount_minor' => 1000,
            'amount_currency' => 'EUR',
            'channel' => 'cash',
            'goods_minor' => 1000,
            'shipping_minor' => 0,
            'adjustment_minor' => 0,
            'deduction_minor' => 0,
            'deduction_reason' => null,
            'status' => 'owed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    /** The CHECK violation as the engine reports it: MySQL 3819, MariaDB 4025 — the code, never the message (CLAUDE.md rule 3's posture). */
    private function assertCheckViolation(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [3819, 4025], 'expected a CHECK constraint violation, got: '.$exception->getMessage());

            return;
        }

        $this->fail('the database accepted a row that breaks a CHECK constraint.');
    }

    // --- the round trip ------------------------------------------------------------------------

    public function test_save_then_find_by_id_round_trips_a_payment_refund(): void
    {
        $refund = $this->refund('payment-1', 500, PaymentRefundStatus::OWED, reason: 'defective', refundedBy: 'staff-7');

        $this->repository()->save($refund);

        $this->assertNotNull($refund->id());

        $reloaded = $this->repository()->findById($refund->id());
        $this->assertNotNull($reloaded);
        $this->assertSame('payment-1', $reloaded->paymentId());
        $this->assertSame('order-1', $reloaded->orderId());
        $this->assertSame(500, $reloaded->amount()->minorValue());
        $this->assertSame('EUR', $reloaded->amount()->currency()->code());
        $this->assertSame(RefundChannel::CASH, $reloaded->channel());
        $this->assertSame('defective', $reloaded->reason());
        $this->assertSame('staff-7', $reloaded->refundedBy());
        $this->assertSame(PaymentRefundStatus::OWED, $reloaded->status());
        $this->assertNull($reloaded->paidOutAt());
    }

    public function test_the_whole_breakdown_and_its_per_line_rows_round_trip(): void
    {
        [$lineA, $lineB] = $this->twoSaleLines();
        $breakdown = new RefundBreakdown(
            goods: $this->amount(900),
            shipping: $this->amount(300),
            adjustment: $this->amount(0),
            deduction: $this->amount(200),
            deductionReason: 'damaged on return',
            lines: [new RefundLine($lineA, $this->amount(900)), new RefundLine($lineB, $this->amount(0))],
        );
        $refund = $this->refund('payment-b', 1000, breakdown: $breakdown);

        $this->repository()->save($refund);

        $reloaded = $this->repository()->findById($refund->id());
        $this->assertSame(1000, $reloaded->amount()->minorValue());
        $this->assertSame(900, $reloaded->breakdown()->goods->minorValue());
        $this->assertSame(300, $reloaded->breakdown()->shipping->minorValue());
        $this->assertSame(200, $reloaded->breakdown()->deduction->minorValue());
        $this->assertSame('damaged on return', $reloaded->breakdown()->deductionReason);
        $this->assertSame([$lineA, $lineB], array_map(static fn (RefundLine $l): string => $l->saleLineId, $reloaded->breakdown()->lines));
        $this->assertSame([900, 0], array_map(static fn (RefundLine $l): int => $l->amount->minorValue(), $reloaded->breakdown()->lines));
        $this->assertSame(2, DB::table('payment_refund_lines')->where('payment_refund_id', $refund->id())->count());
    }

    public function test_the_deduction_is_on_the_refund_and_on_no_line_row(): void
    {
        [$lineA] = $this->twoSaleLines();
        $breakdown = new RefundBreakdown($this->amount(1000), $this->amount(0), $this->amount(0), $this->amount(250), 'restocking fee', [new RefundLine($lineA, $this->amount(1000))]);
        $refund = $this->refund('payment-d', 750, breakdown: $breakdown);
        $this->repository()->save($refund);

        $this->assertSame(250, (int) DB::table('payment_refunds')->where('id', $refund->id())->value('deduction_minor'));
        $this->assertSame([1000], DB::table('payment_refund_lines')->where('payment_refund_id', $refund->id())->pluck('amount_minor')->map(fn ($v) => (int) $v)->all(), 'the line keeps the goods amount; no line is reduced by the deduction');
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_find_by_payment_id_returns_every_matching_refund_with_its_lines(): void
    {
        [$lineA] = $this->twoSaleLines();
        $first = $this->refund('payment-multi', 200, breakdown: new RefundBreakdown($this->amount(200), $this->amount(0), $this->amount(0), $this->amount(0), null, [new RefundLine($lineA, $this->amount(200))]));
        $second = $this->refund('payment-multi', 100, reason: 'second partial return');
        $other = $this->refund('payment-other', 500, PaymentRefundStatus::REQUESTED);

        $this->repository()->save($first);
        $this->repository()->save($second);
        $this->repository()->save($other);

        $results = $this->repository()->findByPaymentId('payment-multi');

        $this->assertCount(2, $results);
        $byId = [];
        foreach ($results as $found) {
            $byId[$found->id()] = $found;
        }
        $this->assertArrayHasKey($first->id(), $byId);
        $this->assertArrayHasKey($second->id(), $byId);
        $this->assertArrayNotHasKey($other->id(), $byId);
        $this->assertCount(1, $byId[$first->id()]->breakdown()->lines);
        $this->assertSame([], $byId[$second->id()]->breakdown()->lines);
    }

    // --- the CHECKs (MySQL/MariaDB) --------------------------------------------------------------

    public function test_the_checks_reject_a_negative_part(): void
    {
        foreach (['goods_minor', 'shipping_minor', 'adjustment_minor', 'deduction_minor'] as $column) {
            $this->assertCheckViolation(fn () => DB::table('payment_refunds')->insert($this->rawRefund([$column => -1, 'amount_minor' => 999, 'deduction_reason' => 'x'])));
        }
    }

    public function test_the_checks_reject_an_inconsistent_total(): void
    {
        // goods 1000 + shipping 0 + adjustment 0 - deduction 0 = 1000, not 1001.
        $this->assertCheckViolation(fn () => DB::table('payment_refunds')->insert($this->rawRefund(['amount_minor' => 1001])));
        // goods 1000 + shipping 300 - deduction 200 = 1100, not 1000.
        $this->assertCheckViolation(fn () => DB::table('payment_refunds')->insert($this->rawRefund(['shipping_minor' => 300, 'deduction_minor' => 200, 'deduction_reason' => 'fee', 'amount_minor' => 1000])));
    }

    public function test_the_checks_reject_a_negative_total(): void
    {
        $this->assertCheckViolation(fn () => DB::table('payment_refunds')->insert($this->rawRefund(['goods_minor' => 0, 'deduction_minor' => 100, 'deduction_reason' => 'fee', 'amount_minor' => -100])));
    }

    public function test_the_checks_reject_a_deduction_without_its_reason(): void
    {
        $this->assertCheckViolation(fn () => DB::table('payment_refunds')->insert($this->rawRefund(['deduction_minor' => 100, 'amount_minor' => 900, 'deduction_reason' => null])));
    }

    public function test_the_checks_accept_a_consistent_row(): void
    {
        DB::table('payment_refunds')->insert($this->rawRefund(['shipping_minor' => 300, 'deduction_minor' => 200, 'deduction_reason' => 'fee', 'amount_minor' => 1100]));

        $this->assertSame(1, DB::table('payment_refunds')->count());
    }

    public function test_the_check_rejects_a_negative_line_amount(): void
    {
        [$lineA] = $this->twoSaleLines();
        $refund = $this->refund('payment-n', 500);
        $this->repository()->save($refund);

        $this->assertCheckViolation(fn () => DB::table('payment_refund_lines')->insert([
            'payment_refund_id' => $refund->id(), 'sale_line_id' => $lineA, 'amount_minor' => -1, 'amount_currency' => 'EUR',
        ]));
    }

    public function test_order_id_and_channel_are_required_by_the_database(): void
    {
        foreach (['order_id', 'channel'] as $column) {
            try {
                DB::table('payment_refunds')->insert($this->rawRefund([$column => null]));
                $this->fail("{$column} must be NOT NULL.");
            } catch (QueryException $exception) {
                $this->assertSame('23000', (string) $exception->errorInfo[0]);
            }
        }
    }

    // --- the lines table's keys ----------------------------------------------------------------------

    public function test_a_sale_line_can_appear_once_per_refund_and_must_exist(): void
    {
        [$lineA] = $this->twoSaleLines();
        $refund = $this->refund('payment-u', 500);
        $this->repository()->save($refund);

        DB::table('payment_refund_lines')->insert(['payment_refund_id' => $refund->id(), 'sale_line_id' => $lineA, 'amount_minor' => 100, 'amount_currency' => 'EUR']);

        // The same (refund, line) twice: SQLSTATE 23000 + the driver's duplicate-key code.
        try {
            DB::table('payment_refund_lines')->insert(['payment_refund_id' => $refund->id(), 'sale_line_id' => $lineA, 'amount_minor' => 50, 'amount_currency' => 'EUR']);
            $this->fail('the UNIQUE (payment_refund_id, sale_line_id) must reject a second row.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->errorInfo[0]);
            $this->assertSame(1062, (int) $exception->errorInfo[1]);
        }

        // A line that does not exist: the real FK refuses it (1452).
        try {
            DB::table('payment_refund_lines')->insert(['payment_refund_id' => $refund->id(), 'sale_line_id' => 987654321, 'amount_minor' => 1, 'amount_currency' => 'EUR']);
            $this->fail('the FK to the sale line must reject an unknown line.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->errorInfo[0]);
            $this->assertSame(1452, (int) $exception->errorInfo[1]);
        }
    }

    // --- the SUM that backs today's payment cap ----------------------------------------------------------

    /**
     * sumCountingForPayment(): every refund whose money is spoken for — OWED,
     * PAID_OUT, REQUESTED, COMPLETED — and nothing that moved no money.
     */
    public function test_sum_counting_for_payment_counts_owed_paid_out_requested_and_completed_only(): void
    {
        $this->repository()->save($this->refund('payment-sum', 200, PaymentRefundStatus::OWED));
        $this->repository()->save($this->refund('payment-sum', 100, PaymentRefundStatus::COMPLETED));
        $this->repository()->save($this->refund('payment-sum', 50, PaymentRefundStatus::REQUESTED));
        DB::table('payment_refunds')->insert($this->rawRefund(['payment_id' => 'payment-sum', 'amount_minor' => 25, 'goods_minor' => 25, 'status' => 'paid_out', 'paid_out_at' => now()]));
        $this->repository()->save($this->refund('payment-sum', 900, PaymentRefundStatus::FAILED, failureReason: 'declined'));
        DB::table('payment_refunds')->insert($this->rawRefund(['payment_id' => 'payment-sum', 'amount_minor' => 700, 'goods_minor' => 700, 'status' => 'cancelled']));
        $this->repository()->save($this->refund('payment-other', 5000, PaymentRefundStatus::OWED));

        $sum = $this->repository()->sumCountingForPayment('payment-sum', 'EUR');

        $this->assertSame(375, $sum->minorValue(), 'owed 200 + completed 100 + requested 50 + paid out 25; failed and cancelled count for nothing');
        $this->assertSame('EUR', $sum->currency()->code());
    }

    /** Nothing refunded yet is a REAL answer: zero Money in the caller's own currency. */
    public function test_sum_counting_for_payment_is_zero_money_when_there_is_none(): void
    {
        $this->repository()->save($this->refund('payment-none', 200, PaymentRefundStatus::FAILED));

        $sum = $this->repository()->sumCountingForPayment('payment-none', 'BGN');

        $this->assertTrue($sum->isZero());
        $this->assertSame('BGN', $sum->currency()->code());
        $this->assertTrue($this->repository()->sumCountingForPayment('payment-absent', 'EUR')->isZero());
    }

    public function test_sum_counting_for_payment_is_computed_in_sql_not_by_loading_rows(): void
    {
        $this->repository()->save($this->refund('payment-sql', 200));
        $this->repository()->save($this->refund('payment-sql', 300));

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->assertSame(500, $this->repository()->sumCountingForPayment('payment-sql', 'EUR')->minorValue());

        $this->assertCount(1, $statements, 'the total is ONE statement, never a get() plus PHP arithmetic');
        $this->assertStringContainsString('sum(`amount_minor`)', strtolower($statements[0]));
        $this->assertStringNotContainsString('select *', strtolower($statements[0]));
    }
}
