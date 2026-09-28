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
use EasyCo\Pricing\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The migration that retires `fulfilled` from `orders.status` — a GATE, not a
 * rewrite: order-lifecycle-design.md §10 stage 1, owner decision D1.
 *
 * WHY THIS FILE EXISTS AT ALL: the migration's whole job is the case where a
 * row still holds the retired value, and no installation checked by this
 * project holds one (all 6 rows of the development database are `placed`), so
 * nothing in the application would ever exercise the refusal. It is proved
 * here instead — the same reason
 * AddProductDeleteToAdministratorRoleMigrationTest exists.
 *
 * THE MIGRATION IS RUN DIRECTLY, not via `artisan migrate`: it has already run
 * against the test database by the time a test starts (RefreshDatabase
 * migrates fresh, and the gate passes there because no row holds the value),
 * so what is exercised here is the migration's own up()/down() against rows
 * this test plants deliberately.
 *
 * ROWS ARE INSERTED THROUGH DB::table() ON PURPOSE: the case under test is a
 * row that already holds a value no PHP enum can produce any more, which is
 * exactly what a store installed before the removal looks like. The FKs the
 * schema requires are real — a Client and a Transaction are created through
 * their own repositories first, as EloquentOrderRepositoryTest does.
 */
class GuardNoFulfilledOrdersMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'packages/EasyCo/Order/database/migrations/2026_09_28_000001_guard_no_fulfilled_orders.php';

    private const RETIRED_STATUS = 'fulfilled';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    /**
     * The refusal as an object, so a test can assert on the message AND on the
     * rows still being exactly where they were — expectException() would let the
     * second half go unproved.
     */
    private function refusal(): RuntimeException
    {
        try {
            $this->migration()->up();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        $this->fail('The migration did not refuse a row that still holds the retired status.');
    }

    public function test_up_passes_and_changes_nothing_when_no_row_holds_the_retired_value(): void
    {
        $placed = $this->insertOrder('placed');

        $this->migration()->up();

        $this->assertSame('placed', $this->statusOf($placed));
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_up_passes_on_an_empty_table(): void
    {
        $this->migration()->up();

        $this->assertSame(0, DB::table('orders')->count());
    }

    /**
     * The case the whole migration exists for: a row that still holds the
     * retired value refuses the deployment, names the count and the id, and
     * leaves the row exactly as it was — a refusal that rewrote it anyway would
     * be the guess this migration refuses to make.
     */
    public function test_up_refuses_a_row_that_still_holds_the_retired_value_and_changes_nothing(): void
    {
        $id = $this->insertOrder(self::RETIRED_STATUS);

        $before = DB::table('orders')->orderBy('id')->get()->toArray();

        $message = $this->refusal()->getMessage();

        $this->assertStringContainsString("orders.status still holds '".self::RETIRED_STATUS."'", $message);
        $this->assertStringContainsString('in 1 row', $message);
        $this->assertStringContainsString('orders.id: '.$id, $message);

        $this->assertSame(self::RETIRED_STATUS, $this->statusOf($id));
        $this->assertEquals($before, DB::table('orders')->orderBy('id')->get()->toArray());
    }

    public function test_the_refusal_names_the_count_and_every_id_when_several_rows_hold_it(): void
    {
        $first = $this->insertOrder(self::RETIRED_STATUS);
        $second = $this->insertOrder(self::RETIRED_STATUS);
        $this->insertOrder('placed');

        $message = $this->refusal()->getMessage();

        // Three rows exist, two of them hold the retired value: the count is the
        // retired ones, never the table's size.
        $this->assertStringContainsString('in 2 rows', $message);
        $this->assertStringContainsString('orders.id: '.$first.', '.$second, $message);
    }

    /**
     * down() cannot undo what up() never did, and must not "restore" a value
     * the enum no longer has — so it is a no-op even here, where it looks most
     * tempting to do something.
     */
    public function test_down_is_a_no_op_even_while_a_row_still_holds_the_value(): void
    {
        $id = $this->insertOrder(self::RETIRED_STATUS);

        $this->migration()->down();

        $this->assertSame(self::RETIRED_STATUS, $this->statusOf($id));
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_down_is_a_no_op_on_an_empty_table(): void
    {
        $this->migration()->down();

        $this->assertSame(0, DB::table('orders')->count());
    }

    private function statusOf(string $id): string
    {
        return (string) DB::table('orders')->where('id', $id)->value('status');
    }

    /**
     * A real `orders` row carrying $status, inserted past the domain layer —
     * which is the point: after the removal no code path can write 'fulfilled'
     * any more. Returns the new id.
     */
    private function insertOrder(string $status): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $client->id(),
            priceableId: 'variation-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'),
            profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-01-01'),
            effectiveAt: new DateTimeImmutable('2026-01-01'),
            productName: 'Product One',
            sku: 'SKU-1',
            regularUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(1000, 'EUR'),
            soldAttributes: [],
        ));

        app(TransactionRepository::class)->save($transaction);

        return (string) DB::table('orders')->insertGetId([
            'client_id' => $client->id(),
            'transaction_id' => $transaction->id(),
            'email' => 'buyer@example.com',
            'currency' => 'EUR',
            'subtotal_minor' => 1000,
            'discount_minor' => 0,
            'total_minor' => 1000,
            'status' => $status,
            'placed_at' => '2026-01-01 12:00:00',
            'delivery_type' => 'street_address',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888123456',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
