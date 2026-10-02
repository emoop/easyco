<?php

namespace Tests\Feature;

use Closure;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The data step of refunds R1a (2026_10_05_000002_map_legacy_payment_refunds):
 * Q1 of the owner's decisions — existing offline refunds become PAID_OUT with the
 * legacy note — and its two refusals.
 *
 * HOW A "LEGACY" ROW IS MADE: the test database is already at the latest schema,
 * where order_id/channel are NOT NULL and the CHECKs stand. So the test takes the
 * constraint step down (_000003's own down(): a DDL, which MySQL commits at once —
 * hence every row planted here is deleted again in `finally`, and the constraint
 * step is put back), plants rows the way the OLD code wrote them (no order id, no
 * channel, no breakdown, status completed/pending/failed), and runs _000002's
 * own up(). Nothing else is faked.
 */
class PaymentRefundLegacyMappingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MAP = 'packages/EasyCo/Payment/database/migrations/2026_10_05_000002_map_legacy_payment_refunds.php';

    private const CONSTRAIN = 'packages/EasyCo/Payment/database/migrations/2026_10_05_000003_constrain_payment_refunds_breakdown.php';

    private function migration(string $path): Migration
    {
        return require base_path($path);
    }

    /** Runs $scenario against a schema where legacy-shaped rows can exist, and always restores it. */
    private function withLegacyShapedTable(Closure $scenario): void
    {
        $this->migration(self::CONSTRAIN)->down();

        try {
            $scenario();
        } finally {
            DB::table('payment_refunds')->delete();
            DB::table('payments')->delete();
            $this->migration(self::CONSTRAIN)->up();
        }
    }

    private function payment(string $orderId, string $method): string
    {
        $payment = Payment::create($orderId, $method, Money::fromMinorUnits(10000, 'EUR'), PaymentStatus::PENDING);
        app(PaymentRepository::class)->save($payment);

        return (string) $payment->id();
    }

    private function legacyRefund(string $paymentId, int $minor, string $status, string $createdAt = '2026-09-01 10:00:00'): int
    {
        return DB::table('payment_refunds')->insertGetId([
            'payment_id' => $paymentId,
            'amount_minor' => $minor,
            'amount_currency' => 'EUR',
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('payment_refunds')->where('id', $id)->first();
    }

    public function test_a_legacy_offline_refund_becomes_paid_out_with_the_legacy_note_and_its_goods_channel_and_order(): void
    {
        $this->withLegacyShapedTable(function (): void {
            $cod = $this->legacyRefund($this->payment('order-7', 'cash_on_delivery'), 500, 'completed', '2026-09-01 10:00:00');
            $bank = $this->legacyRefund($this->payment('order-8', 'bank_transfer'), 700, 'completed', '2026-09-02 11:30:00');

            $this->migration(self::MAP)->up();

            $cashRow = $this->row($cod);
            $this->assertSame('paid_out', $cashRow->status);
            $this->assertSame('order-7', $cashRow->order_id);
            $this->assertSame('cash', $cashRow->channel, 'cash on delivery -> cash');
            $this->assertSame(500, (int) $cashRow->goods_minor, 'the old amount was goods only');
            $this->assertSame(500, (int) $cashRow->amount_minor, 'the total is untouched');
            $this->assertSame(0, (int) $cashRow->shipping_minor);
            $this->assertSame(0, (int) $cashRow->deduction_minor);
            $this->assertSame('2026-09-01 10:00:00', $cashRow->paid_out_at, 'paid_out_at = created_at');
            $this->assertSame('legacy: recorded before the owed/paid model', $cashRow->paid_out_note);

            $bankRow = $this->row($bank);
            $this->assertSame('paid_out', $bankRow->status);
            $this->assertSame('order-8', $bankRow->order_id);
            $this->assertSame('bank', $bankRow->channel, 'anything else -> bank');
            $this->assertSame('2026-09-02 11:30:00', $bankRow->paid_out_at);
        });
    }

    public function test_pending_becomes_requested_and_failed_stays_failed(): void
    {
        $this->withLegacyShapedTable(function (): void {
            $paymentId = $this->payment('order-9', 'cash_on_delivery');
            $pending = $this->legacyRefund($paymentId, 300, 'pending');
            $failed = $this->legacyRefund($paymentId, 200, 'failed');

            $this->migration(self::MAP)->up();

            $this->assertSame('requested', $this->row($pending)->status);
            $this->assertNull($this->row($pending)->paid_out_at);
            $this->assertSame('failed', $this->row($failed)->status);
            $this->assertSame('order-9', $this->row($failed)->order_id);
        });
    }

    public function test_a_refund_whose_payment_does_not_exist_aborts_the_mapping_naming_the_row_and_changes_nothing(): void
    {
        $this->withLegacyShapedTable(function (): void {
            $fine = $this->legacyRefund($this->payment('order-1', 'cash_on_delivery'), 100, 'completed');
            $orphan = $this->legacyRefund('987654', 200, 'completed');
            $junk = $this->legacyRefund('not-a-payment', 300, 'completed');

            try {
                $this->migration(self::MAP)->up();
                $this->fail('the mapping must refuse a refund it cannot give an order to.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("{$orphan}", $exception->getMessage());
                $this->assertStringContainsString("{$junk}", $exception->getMessage());
                $this->assertStringContainsString('Nothing was changed', $exception->getMessage());
            }

            $this->assertSame('completed', $this->row($fine)->status, 'even the mappable row is untouched: all or nothing');
            $this->assertNull($this->row($fine)->order_id);
        });
    }

    public function test_a_completed_refund_against_a_non_offline_method_aborts_the_mapping(): void
    {
        $this->withLegacyShapedTable(function (): void {
            $online = $this->legacyRefund($this->payment('order-3', 'card_online'), 100, 'completed');

            try {
                $this->migration(self::MAP)->up();
                $this->fail('a COMPLETED refund against an online method is an anomaly the mapping must not paper over.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("{$online}", $exception->getMessage());
            }

            $this->assertSame('completed', $this->row($online)->status);
        });
    }

    public function test_the_mapping_down_folds_owed_and_paid_out_back_into_completed_and_refuses_a_cancelled_refund(): void
    {
        $this->withLegacyShapedTable(function (): void {
            $paid = $this->legacyRefund($this->payment('order-4', 'cash_on_delivery'), 100, 'completed');
            $this->migration(self::MAP)->up();
            DB::table('payment_refunds')->where('id', $paid)->update(['status' => 'owed', 'paid_out_at' => null, 'paid_out_note' => null]);

            $this->migration(self::MAP)->down();
            $this->assertSame('completed', $this->row($paid)->status);

            DB::table('payment_refunds')->where('id', $paid)->update(['status' => 'cancelled']);
            try {
                $this->migration(self::MAP)->down();
                $this->fail('CANCELLED has no equivalent in the old model.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("{$paid}", $exception->getMessage());
            }
        });
    }
}
