<?php

namespace Tests\Feature;

use App\Enums\OrderEventType;
use App\Filament\StaffPanelUser;
use App\Services\OrderEventRecorder;
use DateTimeImmutable;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * order-lifecycle-design.md §6.1, §10 stage 3 — the real schema of `order_events`,
 * proven against the engine rather than trusted to the migration file
 * (CLAUDE.md rule 2 and this project's own convention: EloquentOrderRepositoryTest
 * and EloquentPaymentRepositoryTest both read SHOW CREATE TABLE and then try the
 * delete they claim is refused).
 *
 * WHY THE DELETES ARE ATTEMPTED FOR REAL: §11 item 1 says order_events has no
 * deletion path, and §6.1 says the FK's restrictOnDelete() is what PROVES that
 * "rather than assuming it". A migration that names a constraint is not the same
 * as an engine that enforces it, so each refusal below is a real DELETE.
 */
class OrderEventsSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const FK_NAMES = ['oe_order_id_foreign', 'oe_transaction_id_foreign', 'oe_staff_id_foreign'];

    private function createTable(): string
    {
        return (string) DB::select('SHOW CREATE TABLE order_events')[0]->{'Create Table'};
    }

    public function test_the_three_fk_names_are_the_short_explicit_ones_and_each_fits_the_identifier_limit(): void
    {
        $createTable = $this->createTable();

        foreach (self::FK_NAMES as $name) {
            $this->assertStringContainsString("CONSTRAINT `{$name}`", $createTable);
            // CLAUDE.md rule 5 — MySQL/MariaDB stop at 64 characters, and a
            // silently truncated name is a constraint nobody can look up.
            $this->assertLessThanOrEqual(64, strlen($name), "{$name} must fit the 64-character identifier limit");
        }

        $this->assertStringContainsString('FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT', $createTable);
        $this->assertStringContainsString(
            'FOREIGN KEY (`transaction_id`) REFERENCES `operational_sales_transactions` (`id`) ON DELETE RESTRICT',
            $createTable
        );
        $this->assertStringContainsString('FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL', $createTable);
    }

    /**
     * §6.1: no `updated_at`, no `created_at`, no `$table->timestamps()` — an
     * append-only row has no update to stamp. Asserted on the real table, so the
     * absence cannot be undone by accident.
     */
    public function test_it_carries_no_created_at_or_updated_at_columns(): void
    {
        $createTable = $this->createTable();

        $this->assertStringNotContainsString('created_at', $createTable);
        $this->assertStringNotContainsString('updated_at', $createTable);
        $this->assertStringContainsString('`occurred_at` timestamp NOT NULL', $createTable);
    }

    /**
     * §6.1's nullability, proven by real writes rather than by reading DDL text
     * (MySQL's own SHOW CREATE TABLE omits `DEFAULT NULL` for TEXT, and a string
     * assertion on a driver's formatting is a test that breaks for the wrong
     * reason). An event carrying none of the optional facts is ACCEPTED — that is
     * every `payment_confirmed`, `refunded` and statusless `returned` row, and a
     * `note_added` row's own shape — while the two facts every event must have
     * are refused by the engine when they are missing.
     */
    public function test_an_event_carrying_none_of_the_optional_facts_is_accepted(): void
    {
        DB::table('order_events')->insert([
            'order_id' => $this->orderId(),
            'type' => OrderEventType::PAYMENT_CONFIRMED->value,
            'occurred_at' => '2026-09-28 12:00:00',
        ]);

        $row = DB::table('order_events')->sole();

        foreach (['from_status', 'to_status', 'reason', 'transaction_id', 'staff_id', 'staff_name'] as $nullable) {
            $this->assertNull($row->{$nullable}, "`{$nullable}` must be nullable — §6.1");
        }
    }

    public function test_an_event_without_an_order_or_a_type_is_refused_by_the_engine(): void
    {
        $createTable = $this->createTable();

        // The two NOT NULL facts, read off the real table...
        $this->assertStringContainsString('`order_id` bigint unsigned NOT NULL', $createTable);
        $this->assertStringContainsString('`type` varchar(255)', $createTable);

        // ...and then tried, because a column type is not an enforcement.
        foreach (['order_id', 'type'] as $notNullable) {
            try {
                DB::table('order_events')->insert([
                    'order_id' => $notNullable === 'order_id' ? null : $this->orderId(),
                    'type' => $notNullable === 'type' ? null : OrderEventType::REFUNDED->value,
                    'occurred_at' => '2026-09-28 12:30:00',
                ]);

                $this->fail("The engine accepted an order_events row with a NULL {$notNullable}.");
            } catch (QueryException $exception) {
                $this->assertSame('23000', ($exception->errorInfo ?? [])[0] ?? null);
            }
        }

        $this->assertSame(0, DB::table('order_events')->count());
    }

    /**
     * THE REAL PROOF of §11 item 1's "no order-deletion path exists": an order
     * with any history cannot be deleted by any code that tries, including code
     * that bypasses the domain layer entirely. SQLSTATE 23000 plus the FK's own
     * name in the driver's message (CLAUDE.md rule 3 — never a message-only
     * check), and both rows still exactly where they were.
     */
    public function test_deleting_the_order_is_refused_by_the_engine(): void
    {
        $orderId = $this->orderId();
        $this->recordedEvent($orderId, $this->spareTransactionId());

        $this->assertRefusedByForeignKey(
            fn () => DB::table('orders')->where('id', $orderId)->delete(),
            'oe_order_id_foreign'
        );

        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->count());
        $this->assertSame(1, DB::table('order_events')->count());
    }

    /**
     * The return's own Transaction is protected too (§6.1), and this one needs a
     * transaction the order does NOT point at: `orders.transaction_id` is itself
     * restrictOnDelete(), so deleting the placement transaction would be refused
     * by the wrong constraint — which would make a green test mean nothing.
     */
    public function test_deleting_the_returns_own_transaction_is_refused_by_the_engine(): void
    {
        $orderId = $this->orderId();
        $returnTransactionId = $this->spareTransactionId();
        $this->recordedEvent($orderId, $returnTransactionId);

        $this->assertRefusedByForeignKey(
            fn () => DB::table('operational_sales_transactions')->where('id', $returnTransactionId)->delete(),
            'oe_transaction_id_foreign'
        );

        $this->assertSame(1, DB::table('operational_sales_transactions')->where('id', $returnTransactionId)->count());
        $this->assertSame($returnTransactionId, (string) DB::table('order_events')->sole()->transaction_id);
    }

    /**
     * §6.1's staff snapshot, proven the only way a snapshot can be proven: the
     * staff row is REALLY deleted (a raw delete, since StaffModel soft-deletes —
     * a soft delete would never fire the FK at all) and the history remains
     * readable, with the name it had.
     */
    public function test_deleting_the_staff_row_nulls_the_link_but_keeps_the_name_snapshot(): void
    {
        $orderId = $this->orderId();
        $staff = $this->panelStaff();
        $this->recordedEvent($orderId, $this->spareTransactionId(), $staff);

        $this->assertSame((string) $staff->id, (string) DB::table('order_events')->sole()->staff_id);

        DB::table('staff')->where('id', $staff->id)->delete();

        $row = DB::table('order_events')->sole();

        $this->assertNull($row->staff_id);
        $this->assertSame('Ana Petrova', $row->staff_name, 'the snapshot must outlive the row it points at');
    }

    /**
     * One shape for all three refusals: an exception, SQLSTATE 23000, and the
     * driver's own message naming the constraint that fired — with a real failure
     * if the delete is allowed at all, which is the case this file exists for.
     */
    private function assertRefusedByForeignKey(callable $delete, string $constraintName): void
    {
        try {
            $delete();
        } catch (QueryException $exception) {
            $errorInfo = $exception->errorInfo ?? [];

            $this->assertSame('23000', $errorInfo[0] ?? null, 'a foreign-key refusal is SQLSTATE 23000 on every driver');
            $this->assertStringContainsString(
                $constraintName,
                (string) ($errorInfo[2] ?? ''),
                "the driver's own message must name {$constraintName}"
            );

            return;
        }

        $this->fail("The engine allowed a delete that {$constraintName} should have refused.");
    }

    private function orderId(): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $this->spareTransactionId(),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(OrderRepository::class)->save($order);

        return $order->id();
    }

    /**
     * A real Transaction nobody's `orders` row points at — the return's own row
     * for the FK test above, and the placement transaction for orderId().
     */
    private function spareTransactionId(): string
    {
        $transaction = new Transaction(null, Channel::WEB);
        app(TransactionRepository::class)->save($transaction);

        return $transaction->id();
    }

    private function panelStaff(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('order.events.schema@example.com', app(PasswordHasher::class)->hash('password123'), 'Ana Petrova', $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    /**
     * A fully-populated event written through the real writer (§7.2's shape: the
     * goods came back, so it points at the return's own Transaction) — so this
     * file proves the schema against the rows the production writer produces,
     * not against a hand-built insert.
     */
    private function recordedEvent(string $orderId, string $transactionId, ?StaffPanelUser $actor = null): void
    {
        if ($actor !== null) {
            $this->actingAs($actor, 'staff');
        }

        app(OrderEventRecorder::class)->record(
            orderId: $orderId,
            type: OrderEventType::RETURNED,
            fromStatus: null,
            toStatus: null,
            reason: 'two units came back',
            transactionId: $transactionId,
            occurredAt: new DateTimeImmutable('2026-09-28 13:00:00'),
        );
    }
}
