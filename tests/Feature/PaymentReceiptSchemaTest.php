<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R4a-1 (shipping-domain-design.md §7.2.20 §2): what the DATABASE itself refuses — the CHECKs on
 * payment_receipts and payments, the foreign keys, the supersedes unique — and that each down() refuses
 * while data would be lost (CLAUDE.md rule 2). The CHECKs are MySQL/MariaDB only (the suite's database).
 */
class PaymentReceiptSchemaTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    private function confirmedPayment(int $expected = 10000): Payment
    {
        $payment = Payment::create('order-'.uniqid(), 'bank_transfer', Money::fromMinorUnits($expected, 'EUR'), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-10-01 09:00:00'));
        $payment->confirm(new DateTimeImmutable('2026-10-02 10:00:00'));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    private function pendingPayment(): Payment
    {
        $payment = Payment::create('order-'.uniqid(), 'bank_transfer', Money::fromMinorUnits(10000, 'EUR'), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-10-01 09:00:00'));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    private function receiptRow(string|int $paymentId, array $overrides = []): array
    {
        return $overrides + [
            'payment_id' => $paymentId,
            'amount_minor' => 4000,
            'amount_currency' => 'EUR',
            'received_on' => '2026-10-02',
            'bank_reference' => 'REF-1',
            'supersedes_receipt_id' => null,
            'recorded_by' => '3',
            'recorded_at' => '2026-10-02 10:00:00',
        ];
    }

    private function assertRefused(callable $write, string $why): void
    {
        try {
            $write();
            $this->fail($why.' must be refused by the database.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->errorInfo, $why);
        }
    }

    // ---- payment_receipts --------------------------------------------------------------------

    public function test_a_valid_receipt_is_stored(): void
    {
        $payment = $this->confirmedPayment();

        DB::table('payment_receipts')->insert($this->receiptRow($payment->id()));

        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_the_amount_check_refuses_zero_and_negative(): void
    {
        $payment = $this->confirmedPayment();

        foreach ([0, -1, -4000] as $amount) {
            $this->assertRefused(fn () => DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['amount_minor' => $amount])), "an amount of {$amount}");
        }

        $this->assertSame(0, DB::table('payment_receipts')->count());
    }

    public function test_the_reference_check_refuses_a_blank_reference(): void
    {
        $payment = $this->confirmedPayment();

        foreach ([' ', '   ', "\t"] as $blank) {
            $this->assertRefused(fn () => DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['bank_reference' => $blank])), 'a blank reference');
        }

        $this->assertSame(0, DB::table('payment_receipts')->count());
    }

    public function test_the_reference_column_is_64_characters_wide(): void
    {
        $payment = $this->confirmedPayment();

        DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['bank_reference' => str_repeat('x', 64)]));
        $this->assertRefused(fn () => DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['bank_reference' => str_repeat('x', 65)])), 'a 65-character reference');
    }

    public function test_the_payment_foreign_key_refuses_an_unknown_payment_and_restricts_deleting_one_with_receipts(): void
    {
        $this->assertRefused(fn () => DB::table('payment_receipts')->insert($this->receiptRow(999999)), 'a receipt for a payment that does not exist');

        $payment = $this->confirmedPayment();
        DB::table('payment_receipts')->insert($this->receiptRow($payment->id()));

        $this->assertRefused(fn () => DB::table('payments')->where('id', $payment->id())->delete(), 'deleting a payment that has receipts');
    }

    public function test_the_supersedes_foreign_key_refuses_an_unknown_receipt_and_restricts_deleting_a_superseded_one(): void
    {
        $payment = $this->confirmedPayment();

        $this->assertRefused(fn () => DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['supersedes_receipt_id' => 999999])), 'superseding a receipt that does not exist');

        $first = DB::table('payment_receipts')->insertGetId($this->receiptRow($payment->id()));
        DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['supersedes_receipt_id' => $first, 'bank_reference' => 'REF-FIXED']));

        $this->assertRefused(fn () => DB::table('payment_receipts')->where('id', $first)->delete(), 'deleting a receipt that has been superseded');
    }

    public function test_a_receipt_can_be_superseded_only_once(): void
    {
        $payment = $this->confirmedPayment();
        $first = DB::table('payment_receipts')->insertGetId($this->receiptRow($payment->id()));

        DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['supersedes_receipt_id' => $first, 'bank_reference' => 'REF-A']));

        $this->assertRefused(fn () => DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['supersedes_receipt_id' => $first, 'bank_reference' => 'REF-B'])), 'a second correction of the same receipt');
    }

    public function test_many_receipts_without_a_supersedes_do_not_collide(): void
    {
        $payment = $this->confirmedPayment();

        foreach (['A', 'B', 'C'] as $reference) {
            DB::table('payment_receipts')->insert($this->receiptRow($payment->id(), ['bank_reference' => $reference]));
        }

        $this->assertSame(3, DB::table('payment_receipts')->count(), 'NULL supersedes values never conflict');
    }

    // ---- payments ----------------------------------------------------------------------------

    public function test_a_payment_accepts_a_settlement_only_in_its_valid_shape(): void
    {
        $payment = $this->confirmedPayment(10000);

        // The one valid shape: both, positive, different, on a confirmed payment.
        DB::table('payments')->where('id', $payment->id())->update(['settled_amount_minor' => 9000, 'settlement_reason' => 'agreed by phone']);
        $this->assertSame(9000, (int) DB::table('payments')->where('id', $payment->id())->value('settled_amount_minor'));

        $other = $this->confirmedPayment(10000);

        $bad = [
            'an amount without a reason' => ['settled_amount_minor' => 9000, 'settlement_reason' => null],
            'a reason without an amount' => ['settled_amount_minor' => null, 'settlement_reason' => 'why'],
            'a zero amount' => ['settled_amount_minor' => 0, 'settlement_reason' => 'why'],
            'a negative amount' => ['settled_amount_minor' => -5, 'settlement_reason' => 'why'],
            'the expected amount itself' => ['settled_amount_minor' => 10000, 'settlement_reason' => 'why'],
        ];

        foreach ($bad as $what => $values) {
            $this->assertRefused(fn () => DB::table('payments')->where('id', $other->id())->update($values), $what);
        }

        $this->assertNull(DB::table('payments')->where('id', $other->id())->value('settled_amount_minor'));
    }

    public function test_a_settlement_amount_needs_a_confirmed_payment(): void
    {
        $pending = $this->pendingPayment();

        $this->assertRefused(
            fn () => DB::table('payments')->where('id', $pending->id())->update(['settled_amount_minor' => 9000, 'settlement_reason' => 'why']),
            'an accepted amount on a payment that was never confirmed',
        );
    }

    public function test_the_settlement_reason_column_is_255_characters_wide(): void
    {
        $payment = $this->confirmedPayment();

        DB::table('payments')->where('id', $payment->id())->update(['settled_amount_minor' => 9000, 'settlement_reason' => str_repeat('x', 255)]);

        $other = $this->confirmedPayment();
        $this->assertRefused(fn () => DB::table('payments')->where('id', $other->id())->update(['settled_amount_minor' => 9000, 'settlement_reason' => str_repeat('x', 256)]), 'a 256-character reason');
    }

    // ---- order_events ------------------------------------------------------------------------

    public function test_an_order_event_can_point_at_a_payment_and_a_receipt_and_only_at_real_ones(): void
    {
        $this->actingAsAdministrator();
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], method: 'bank_transfer');
        $receipt = DB::table('payment_receipts')->insertGetId($this->receiptRow($order['payment']->id()));

        $event = [
            'order_id' => $order['orderId'],
            'type' => 'note_added',
            'reason' => 'x',
            'occurred_at' => '2026-10-02 10:00:00',
        ];

        DB::table('order_events')->insert($event + ['payment_id' => $order['payment']->id(), 'payment_receipt_id' => $receipt]);
        $this->assertSame(1, DB::table('order_events')->whereNotNull('payment_receipt_id')->count());

        $this->assertRefused(fn () => DB::table('order_events')->insert($event + ['payment_id' => 999999]), 'an event pointing at no payment');
        $this->assertRefused(fn () => DB::table('order_events')->insert($event + ['payment_receipt_id' => 999999]), 'an event pointing at no receipt');
        $this->assertRefused(fn () => DB::table('payment_receipts')->where('id', $receipt)->delete(), 'deleting a receipt an event points at');
    }

    // ---- the indexes ---------------------------------------------------------------------------

    public function test_the_receipt_and_refund_indexes_exist(): void
    {
        $index = fn (string $table, string $name): bool => DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('index_name', $name)->exists();

        $this->assertTrue($index('payment_receipts', 'prc_payment_idx'));
        $this->assertTrue($index('payment_receipts', 'prc_supersedes_unique'));
        $this->assertTrue($index('payment_refunds', 'pay_refunds_status_created_idx'));
    }

    // ---- down() ------------------------------------------------------------------------------

    private function migration(string $path): Migration
    {
        return require base_path($path);
    }

    public function test_down_refuses_while_data_would_be_lost(): void
    {
        $payment = $this->confirmedPayment();
        DB::table('payment_receipts')->insert($this->receiptRow($payment->id()));
        DB::table('payments')->where('id', $payment->id())->update(['settled_amount_minor' => 9000, 'settlement_reason' => 'why']);
        $this->actingAsAdministrator();
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], method: 'bank_transfer');
        DB::table('order_events')->insert(['order_id' => $order['orderId'], 'type' => 'note_added', 'reason' => 'x', 'occurred_at' => '2026-10-02 10:00:00', 'payment_id' => $payment->id()]);

        $migrations = [
            'packages/EasyCo/Payment/database/migrations/2026_10_08_000001_create_payment_receipts_table.php' => 'payment_receipts holds receipts',
            'packages/EasyCo/Payment/database/migrations/2026_10_08_000002_add_accepted_settlement_to_payments_table.php' => 'accepted settlement amount',
            'database/migrations/2026_10_08_000004_add_payment_references_to_order_events.php' => 'payment or receipt reference',
        ];

        foreach ($migrations as $path => $message) {
            try {
                $this->migration($path)->down();
                $this->fail("{$path} must refuse to roll back with data.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), $path);
            }
        }

        // Nothing was changed by the refusals.
        $this->assertSame(1, DB::table('payment_receipts')->count());
        $this->assertSame(9000, (int) DB::table('payments')->where('id', $payment->id())->value('settled_amount_minor'));
        $this->assertSame(1, DB::table('order_events')->whereNotNull('payment_id')->count());
    }
}
