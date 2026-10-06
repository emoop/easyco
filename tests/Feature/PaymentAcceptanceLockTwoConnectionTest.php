<?php

namespace Tests\Feature;

use App\Services\Exceptions\PaymentReceiptRefusedException;
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
 * Refunds R4a-3 (shipping-domain-design.md §7.2.20 §10): an acceptance and a receipt on the same order serialise
 * under the order lock. Same deterministic shape as PaymentReceiptLockTwoConnectionTest, no race: connection B
 * holds the order's row lock; the acceptance on connection A stops at it (InnoDB lock wait timeout, error 1205)
 * having written NOTHING; once B lets go the acceptance goes through — and a receipt recorded after it finds a
 * payment that is already settled, because it reads AFTER taking the lock, so no stale snapshot hides the
 * acceptance. A correction waits at the same lock.
 *
 * No RefreshDatabase: the fixture is COMMITTED so the second connection can lock it, and removed in `finally`.
 */
class PaymentAcceptanceLockTwoConnectionTest extends TestCase
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
        foreach (['order_events', 'payment_receipts', 'payments', 'orders', 'operational_sales_sale_lines', 'operational_sales_transactions', 'operational_sales_clients', 'staff', 'staff_roles'] as $table) {
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

    public function test_an_acceptance_and_a_receipt_on_the_same_order_serialise_under_the_order_lock(): void
    {
        $this->cleanUp();
        $holder = null;

        try {
            $this->actingAsAdministrator();
            $order = $this->pendingBankOrder();
            $paymentId = (string) $order['payment']->id();
            $recorder = app(PaymentReceiptRecorder::class);
            $first = $recorder->record($paymentId, $this->eur(9000), '2026-09-28', 'FIRST', $this->at());

            // B takes and holds the order's row lock.
            $holder = DB::connection(self::SECOND);
            $holder->beginTransaction();
            $holder->selectOne('select id from orders where id = ? for update', [$order['orderId']]);

            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $recorder->acceptMismatch($paymentId, $this->eur(9000), 'agreed by phone', $this->at(), 'k-accept');
                $this->fail('the acceptance got past a row lock another connection holds.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1], 'InnoDB lock wait timeout: the acceptance was waiting on the order lock');
            }

            try {
                $recorder->correct((string) $first->receipt()->id(), $this->eur(8000), '2026-09-28', 'FIRST', 'it was 80', $this->at(), 'k-correct');
                $this->fail('the correction got past a row lock another connection holds.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1], 'a correction waits at the same lock');
            }

            $this->assertSame(1, DB::table('payment_receipts')->count(), 'it stopped at the lock: nothing written');
            $this->assertSame(1, DB::table('order_events')->where('order_id', $order['orderId'])->count());
            $this->assertNull(DB::table('payments')->where('id', $paymentId)->value('confirmed_at'));

            // B lets go: the very same acceptance now goes through.
            $holder->rollBack();
            $holder = null;

            $accepted = $recorder->acceptMismatch($paymentId, $this->eur(9000), 'agreed by phone', $this->at(), 'k-accept');
            $this->assertFalse($accepted->wasReplay());
            $this->assertTrue($accepted->payment()->isSettled());
            $this->assertSame(9000, (int) DB::table('payments')->where('id', $paymentId)->value('settled_amount_minor'));

            // A receipt recorded after it sees the settled payment (it reads after its own lock).
            try {
                $recorder->record($paymentId, $this->eur(1000), '2026-09-28', 'SECOND', $this->at());
                $this->fail('the payment is settled by the acceptance.');
            } catch (PaymentReceiptRefusedException $exception) {
                $this->assertSame(PaymentReceiptRefusedException::PAYMENT_SETTLED, $exception->reason);
            }

            $this->assertSame(1, DB::table('payment_receipts')->count());
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
