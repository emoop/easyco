<?php

namespace Tests\Feature;

use App\Services\OrderStatusChanger;
use App\Services\RefundRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R1b (shipping-domain-design.md §7.2.3): the order row lock is THE
 * serialization point of everything that moves an order's money, so two refunds
 * cannot both read "refunded so far" and both pass a cap.
 *
 * DETERMINISTIC, NO RACE: two real database connections. Connection B opens a
 * transaction and takes the order's row lock (SELECT … FOR UPDATE) — and holds it.
 * Connection A then runs the real cancel; it must stop at the lock (InnoDB lock
 * wait timeout, shortened to 1 second on A's session, error 1205) having written
 * NOTHING. Once B lets go, the same cancel goes through. No timing is asserted
 * beyond "B still holds the lock when A asks", which the test itself guarantees.
 *
 * WHY THIS CLASS DOES NOT USE RefreshDatabase: that wraps each test in a
 * transaction, and rows inside an uncommitted transaction are invisible to the
 * second connection — there would be nothing for B to lock. So the fixture is
 * COMMITTED (autocommit) and removed again in `finally`; the tables it touches
 * are emptied in dependency order. It needs no staff and no payment (a cancel
 * of an order with no payment moves no money and needs no refund permission).
 */
class OrderRefundLockTwoConnectionTest extends TestCase
{
    use BuildsRefundableOrders;

    private const SECOND = 'mysql_lock_holder';

    protected function setUp(): void
    {
        parent::setUp();

        // The suite's other classes migrate the test database; make this one safe to run alone.
        $this->artisan('migrate');
        config(['database.connections.'.self::SECOND => config('database.connections.'.config('database.default'))]);
    }

    private function cleanUp(): void
    {
        foreach (['order_events', 'orders', 'operational_sales_sale_lines', 'operational_sales_transactions', 'operational_sales_clients'] as $table) {
            DB::table($table)->delete();
        }
    }

    public function test_while_one_connection_holds_the_order_lock_a_cancel_on_another_cannot_proceed_past_it(): void
    {
        $this->cleanUp();
        $holder = null;

        try {
            $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false, restockable: false);
            [$line] = $order['saleLineIds'];
            $noRestock = [$line => false];

            // B takes and holds the order's row lock.
            $holder = DB::connection(self::SECOND);
            $holder->beginTransaction();
            $holder->selectOne('select id from orders where id = ? for update', [$order['orderId']]);

            // A asks for the same lock through the real service and gives up after 1 second.
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at(), null, $noRestock, new RefundRequest(operationKey: 'locked-out'));
                $this->fail('the cancel got past a row lock another connection holds.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1], 'InnoDB lock wait timeout: the cancel was waiting on the order lock');
            }

            // It stopped at the lock: no status change, no REFUND line, no event.
            $this->assertSame('shipped', DB::table('orders')->where('id', $order['orderId'])->value('status'));
            $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
            $this->assertSame(0, DB::table('order_events')->where('order_id', $order['orderId'])->count());

            // B lets go: the very same call now goes through.
            $holder->rollBack();
            $holder = null;

            $result = app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at(), null, $noRestock, new RefundRequest(operationKey: 'locked-out'));

            $this->assertFalse($result->wasReplay());
            $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
            $this->assertSame(['returned', 'status_changed'], $this->eventTypesOf($order['orderId']));
        } finally {
            if ($holder !== null) {
                $holder->rollBack();
            }

            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            DB::purge(self::SECOND);
            $this->cleanUp();
        }
    }

    public function test_the_lock_is_taken_on_the_order_row_so_a_different_order_is_not_held_up(): void
    {
        $this->cleanUp();
        $holder = null;

        try {
            $locked = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false, restockable: false);
            $free = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false, restockable: false);

            $holder = DB::connection(self::SECOND);
            $holder->beginTransaction();
            $holder->selectOne('select id from orders where id = ? for update', [$locked['orderId']]);

            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            $result = app(OrderStatusChanger::class)->cancel($free['orderId'], $this->at(), null, [$free['saleLineIds'][0] => false]);

            $this->assertFalse($result->wasReplay(), 'only the locked order is serialized; another order proceeds');
            $this->assertSame('cancelled', DB::table('orders')->where('id', $free['orderId'])->value('status'));
        } finally {
            if ($holder !== null) {
                $holder->rollBack();
            }

            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            DB::purge(self::SECOND);
            $this->cleanUp();
        }
    }
}
