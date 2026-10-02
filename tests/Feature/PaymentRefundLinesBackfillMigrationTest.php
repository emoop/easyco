<?php

namespace Tests\Feature;

use App\Services\OrderStatusChanger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R1b: refunds written before R1a have no payment_refund_lines, and the
 * per-line cap sums them. The migration gives them theirs from the REFUND sale
 * lines of the same return (the `returned` / `refunded` events share the return's
 * transaction id) — and is a GATE: ambiguity or a sum that does not add up aborts
 * naming the refund ids.
 *
 * A "legacy" refund is made by doing a real return and then undoing what R1a
 * added: its line rows are deleted and its event is turned back into `refunded`
 * (the type the code wrote before the owed model). Nothing else is faked.
 */
class PaymentRefundLinesBackfillMigrationTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_06_000002_backfill_payment_refund_lines.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    private function returnUnits(array $order, int $line, int $quantity): void
    {
        app(OrderStatusChanger::class)->recordReturn(
            $order['orderId'],
            [['originatingSaleLineId' => $order['saleLineIds'][$line], 'quantityReturned' => $quantity, 'restock' => true]],
            $this->at(),
        );
    }

    /** Turns the refunds of $order into pre-R1a ones. */
    private function makeLegacy(array $order): void
    {
        DB::table('payment_refund_lines')->delete();
        DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'refund_owed')->update(['type' => 'refunded', 'payment_refund_id' => null]);
    }

    private function lineRows(): array
    {
        return DB::table('payment_refund_lines')->orderBy('payment_refund_id')->orderBy('sale_line_id')->get()
            ->map(fn ($r) => [(int) $r->payment_refund_id, (string) $r->sale_line_id, (int) $r->amount_minor])->all();
    }

    public function test_the_backfill_gives_each_legacy_refund_the_lines_of_its_own_return(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000], ['quantity' => 2, 'unit' => 400]]);
        $this->returnUnits($order, 0, 2);   // refund 1: line 0, 2000
        $this->returnUnits($order, 1, 1);   // refund 2: line 1, 400
        $this->returnUnits($order, 0, 1);   // refund 3: line 0, 1000

        $before = $this->lineRows();
        $this->assertCount(3, $before);

        $this->makeLegacy($order);
        $this->assertSame([], $this->lineRows());

        $this->migration()->up();

        $this->assertSame($before, $this->lineRows(), 'each refund got exactly the line rows its return produced');
    }

    public function test_the_backfill_leaves_refunds_that_already_have_lines_alone_and_is_safe_to_rerun(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $this->returnUnits($order, 0, 2);
        $before = $this->lineRows();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($before, $this->lineRows());
    }

    public function test_a_refund_with_no_goods_needs_no_lines(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], shippingMinor: 300);
        DB::table('payment_refunds')->insert([
            'payment_id' => $order['payment']->id(), 'order_id' => $order['orderId'], 'amount_minor' => 300, 'amount_currency' => 'EUR',
            'channel' => 'cash', 'goods_minor' => 0, 'shipping_minor' => 300, 'status' => 'owed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame([], $this->lineRows());
    }

    public function test_it_aborts_naming_the_refund_when_its_return_cannot_be_matched_to_an_event(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $this->returnUnits($order, 0, 1);
        $this->returnUnits($order, 0, 1);
        $ids = DB::table('payment_refunds')->orderBy('id')->pluck('id')->all();

        $this->makeLegacy($order);
        // One refund event goes missing: two refunds, one event — which refund is which is a guess.
        DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'refunded')->orderByDesc('id')->limit(1)->delete();

        try {
            $this->migration()->up();
            $this->fail('an ambiguous legacy refund must stop the migration.');
        } catch (RuntimeException $exception) {
            foreach ($ids as $id) {
                $this->assertStringContainsString((string) $id, $exception->getMessage());
            }
            $this->assertStringContainsString('Nothing was changed', $exception->getMessage());
        }

        $this->assertSame([], $this->lineRows(), 'nothing was written');
    }

    public function test_it_aborts_naming_the_refund_when_the_lines_do_not_add_up_to_its_goods(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $this->returnUnits($order, 0, 2);
        $this->returnUnits($order, 0, 1);
        [$good, $tampered] = DB::table('payment_refunds')->orderBy('id')->pluck('id')->all();

        $this->makeLegacy($order);
        // The second return's REFUND line no longer agrees with its refund.
        $secondTransaction = DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'refunded')->orderByDesc('id')->value('transaction_id');
        DB::table('operational_sales_sale_lines')->where('transaction_id', $secondTransaction)->update(['actual_refund_amount_minor' => 999]);

        try {
            $this->migration()->up();
            $this->fail('lines that do not sum to the refund\'s goods must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $tampered, $exception->getMessage());
            $this->assertStringNotContainsString("id {$good},", $exception->getMessage().',', 'only the refund that cannot be matched is named');
        }

        $this->assertSame([], $this->lineRows(), 'all or nothing: even the matchable refund got no rows');
    }

    public function test_it_aborts_for_a_refund_with_goods_and_no_event_at_all(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);
        DB::table('payment_refunds')->insert([
            'payment_id' => $order['payment']->id(), 'order_id' => $order['orderId'], 'amount_minor' => 500, 'amount_currency' => 'EUR',
            'channel' => 'cash', 'goods_minor' => 500, 'status' => 'paid_out', 'paid_out_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('payment_refunds')->value('id');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage((string) $id);

        $this->migration()->up();
    }
}
