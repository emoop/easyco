<?php

namespace Tests\Feature;

use App\Services\PaymentReceiptRecorder;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R4a-2 (shipping-domain-design.md §7.2.20 §3, §10): the order row lock serialises two receipts.
 *
 * Same deterministic shape as OrderRefundLockTwoConnectionTest, no race: connection B opens a transaction and
 * holds the order's row lock (SELECT … FOR UPDATE). The receipt service on connection A must stop at that
 * lock (InnoDB lock wait timeout, 1 second on A's session, error 1205) having written NOTHING; once B lets
 * go, the same call goes through, and a second receipt then sees the first one's sum (it is read AFTER the
 * lock was taken, so no stale snapshot hides it) and settles the payment.
 *
 * No RefreshDatabase: the fixture is COMMITTED so the second connection can lock it, and removed in `finally`.
 */
class PaymentReceiptLockTwoConnectionTest extends TestCase
{
    use BuildsRefundableOrders;

    private const SECOND = 'mysql_lock_holder';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');
        config(['database.connections.'.self::SECOND => config('database.connections.'.config('database.default'))]);
    }

    private function cleanUp(): void
    {
        foreach (['order_events', 'payment_receipts', 'payments', 'orders', 'operational_sales_sale_lines', 'operational_sales_transactions', 'operational_sales_clients'] as $table) {
            DB::table($table)->delete();
        }
    }

    /** @return array{orderId: string, payment: Payment} */
    private function pendingBankOrder(): array
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], status: OrderStatus::PLACED, settle: false, restockable: false);
        $payment = Payment::create($order['orderId'], 'bank_transfer', $this->eur(10000), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($payment);

        return ['orderId' => $order['orderId'], 'payment' => $payment];
    }

    public function test_two_receipts_are_serialised_by_the_order_lock(): void
    {
        $this->cleanUp();
        $holder = null;

        try {
            $order = $this->pendingBankOrder();
            $paymentId = (string) $order['payment']->id();
            $recorder = app(PaymentReceiptRecorder::class);

            // B takes and holds the order's row lock.
            $holder = DB::connection(self::SECOND);
            $holder->beginTransaction();
            $holder->selectOne('select id from orders where id = ? for update', [$order['orderId']]);

            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $recorder->record($paymentId, $this->eur(6000), '2026-09-28', 'FIRST', $this->at(), 'locked-out');
                $this->fail('the receipt got past a row lock another connection holds.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1], 'InnoDB lock wait timeout: the receipt was waiting on the order lock');
            }

            $this->assertSame(0, DB::table('payment_receipts')->count(), 'it stopped at the lock: nothing written');
            $this->assertSame(0, DB::table('order_events')->where('order_id', $order['orderId'])->count());

            // B lets go: the very same call now goes through.
            $holder->rollBack();
            $holder = null;

            $first = $recorder->record($paymentId, $this->eur(6000), '2026-09-28', 'FIRST', $this->at(), 'locked-out');
            $this->assertFalse($first->wasReplay());
            $this->assertFalse($first->settled());

            // The second receipt reads what the first committed (after its own lock) and completes the amount.
            $second = $recorder->record($paymentId, $this->eur(4000), '2026-09-28', 'SECOND', $this->at(), 'second');
            $this->assertTrue($second->settled());
            $this->assertSame(2, DB::table('payment_receipts')->count());
            $this->assertNotNull(DB::table('payments')->where('id', $paymentId)->value('confirmed_at'));
        } finally {
            if ($holder !== null) {
                $holder->rollBack();
            }

            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            DB::purge(self::SECOND);
            $this->cleanUp();
        }
    }

    public function test_the_lock_is_the_orders_so_a_receipt_on_another_order_is_not_held_up(): void
    {
        $this->cleanUp();
        $holder = null;

        try {
            $locked = $this->pendingBankOrder();
            $free = $this->pendingBankOrder();

            $holder = DB::connection(self::SECOND);
            $holder->beginTransaction();
            $holder->selectOne('select id from orders where id = ? for update', [$locked['orderId']]);

            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            $result = app(PaymentReceiptRecorder::class)->record((string) $free['payment']->id(), $this->eur(10000), '2026-09-28', 'FREE', $this->at());

            $this->assertTrue($result->settled(), 'only the locked order is serialised; another order proceeds');
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
