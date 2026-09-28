<?php

namespace Tests\Feature;

use App\Enums\OrderEventType;
use App\Filament\StaffPanelUser;
use App\Models\User;
use App\Services\OrderEventRecorder;
use App\Settings\Contracts\SiteSettingsRepository;
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
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §6.2, §10 stage 3 — App\Services\OrderEventRecorder:
 * the always-on, insert-only writer of order_events.
 *
 * WHY THESE TESTS ARE THE POINT OF THE CLASS: nothing in production calls the
 * recorder yet (§10 stages 4-6 wire it into the services that change a status),
 * so this file is the only thing standing behind §6.2's promises — always on
 * regardless of the activity-log setting, one INSERT and no other statement,
 * the actor resolved rather than passed, and an event that is part of the
 * caller's transaction rather than a notification outside it.
 *
 * ORDERS ARE NOT PLACED THROUGH CheckoutOrchestrator HERE, deliberately: this
 * class's own contract is a string order id plus a real FK. The fixture builds a
 * real `orders` row through Order + EloquentOrderRepository (the same shape
 * EloquentOrderRepositoryTest uses) so the FK is genuinely satisfied, without
 * dragging a cart, pricing lists and stock into a test about a journal.
 */
class OrderEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function recorder(): OrderEventRecorder
    {
        return app(OrderEventRecorder::class);
    }

    private function actingAsPanelStaff(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('order.events@example.com', app(PasswordHasher::class)->hash('password123'), 'Ana Petrova', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function placementTransactionId(string $clientId): string
    {
        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: 'variation-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'),
            profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            effectiveAt: new DateTimeImmutable('2026-09-28 09:00:00'),
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

        return $transaction->id();
    }

    /**
     * A real `orders` row, and — because §6.2's `$transactionId` is a real FK —
     * the id of a SECOND transaction that is nobody's placement transaction, so
     * an event pointing at it proves nothing about the order's own row.
     */
    private function orderAndSpareTransaction(): array
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $this->placementTransactionId($client->id()),
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

        $spare = new Transaction(null, Channel::WEB);
        app(TransactionRepository::class)->save($spare);

        return [$order->id(), $spare->id()];
    }

    private function eventRow(string $orderId): object
    {
        return DB::table('order_events')->where('order_id', $orderId)->sole();
    }

    public function test_it_inserts_one_row_carrying_every_field_with_the_panel_actor(): void
    {
        [$orderId, $spareTransactionId] = $this->orderAndSpareTransaction();
        $staff = $this->actingAsPanelStaff();

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::STATUS_CHANGED,
            fromStatus: OrderStatus::PLACED,
            toStatus: OrderStatus::CONFIRMED,
            reason: 'customer rang to confirm the address',
            transactionId: $spareTransactionId,
            occurredAt: new DateTimeImmutable('2026-09-28 11:30:00'),
        );

        $row = $this->eventRow($orderId);

        $this->assertSame($orderId, (string) $row->order_id);
        $this->assertSame('status_changed', $row->type);
        $this->assertSame('placed', $row->from_status);
        $this->assertSame('confirmed', $row->to_status);
        $this->assertSame('customer rang to confirm the address', $row->reason);
        $this->assertSame($spareTransactionId, (string) $row->transaction_id);
        $this->assertSame((string) $staff->id, (string) $row->staff_id);
        // The SNAPSHOT, not a join (§6.1) — it stays readable after a rename.
        $this->assertSame('Ana Petrova', $row->staff_name);
        $this->assertSame('2026-09-28 11:30:00', (string) $row->occurred_at);
    }

    /**
     * §6.2: "a console caller records NULL rather than failing" — no exception,
     * no guessed actor, and no call for a staff id anywhere in the signature.
     */
    public function test_a_console_caller_records_a_null_actor_rather_than_failing(): void
    {
        [$orderId] = $this->orderAndSpareTransaction();

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::PAYMENT_CONFIRMED,
            fromStatus: null,
            toStatus: null,
            reason: null,
            transactionId: null,
            occurredAt: new DateTimeImmutable('2026-09-28 12:00:00'),
        );

        $row = $this->eventRow($orderId);

        $this->assertNull($row->staff_id);
        $this->assertNull($row->staff_name);
        $this->assertNull($row->from_status);
        $this->assertNull($row->to_status);
    }

    /**
     * PanelStaffActor's own rule, exercised through this writer: an
     * authenticated user that is not a StaffModel is not an actor. Recorded as
     * NULL, never as a wrong staff id.
     */
    public function test_an_authenticated_non_staff_user_also_records_a_null_actor(): void
    {
        [$orderId] = $this->orderAndSpareTransaction();

        $this->actingAs(new User(['name' => 'Someone', 'email' => 'someone@example.com']), 'staff');

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::REFUNDED,
            fromStatus: null,
            toStatus: null,
            reason: null,
            transactionId: null,
            occurredAt: new DateTimeImmutable('2026-09-28 12:30:00'),
        );

        $row = $this->eventRow($orderId);

        $this->assertNull($row->staff_id);
        $this->assertNull($row->staff_name);
    }

    /**
     * §0 item 8 and §6.2's "always on": the activity log's own Site Setting
     * governs activity_log, and only activity_log. Setting it to '0' — the
     * default a fresh install ships with — must not stop this write, and must
     * not stop ActivityLogger's deliberately-excepted logDeleted() either.
     */
    public function test_it_writes_when_the_activity_log_setting_is_off(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '0');
        [$orderId] = $this->orderAndSpareTransaction();

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::RETURNED,
            fromStatus: null,
            toStatus: null,
            reason: 'two units came back damaged',
            transactionId: null,
            occurredAt: new DateTimeImmutable('2026-09-28 13:00:00'),
        );

        $this->assertSame(1, DB::table('order_events')->count());
        $this->assertSame('returned', $this->eventRow($orderId)->type);
    }

    /**
     * §6.2's "never updated, never deleted — the only statement this class ever
     * issues is an insert". Captured for real: the recorder runs with nothing
     * else in flight, so the statement list is exactly what the class did.
     */
    public function test_the_only_statement_it_issues_is_one_insert_into_order_events(): void
    {
        [$orderId] = $this->orderAndSpareTransaction();
        $this->actingAsPanelStaff();

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::STATUS_CHANGED,
            fromStatus: OrderStatus::CONFIRMED,
            toStatus: OrderStatus::SHIPPED,
            reason: null,
            transactionId: null,
            occurredAt: new DateTimeImmutable('2026-09-28 14:00:00'),
        );

        DB::flushQueryLog();

        $this->assertCount(1, $statements, 'the recorder must issue exactly one statement — no read, no update, no delete');
        $this->assertStringContainsString('insert into `order_events`', strtolower($statements[0]));
    }

    /**
     * §6.1's shape, enforced at the writer: from_status and to_status are both
     * set for a transition and BOTH NULL for every event that moved no status.
     * Half a pair describes a state this table can hold but no fact it can be
     * about, so it is refused loudly and NOTHING is written — a caller that
     * passed one by mistake must not leave a half-truth in an always-on history.
     */
    public function test_it_refuses_a_run_with_only_one_of_the_two_statuses(): void
    {
        [$orderId] = $this->orderAndSpareTransaction();

        try {
            $this->recorder()->record(
                orderId: $orderId,
                type: OrderEventType::STATUS_CHANGED,
                fromStatus: OrderStatus::PLACED,
                toStatus: null,
                reason: null,
                transactionId: null,
                occurredAt: new DateTimeImmutable('2026-09-28 15:00:00'),
            );

            $this->fail('The recorder accepted a status pair with only from_status set.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('from_status and to_status', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('order_events')->count());
    }

    /**
     * NOTE_ADDED's own two guards (owner decision D5, and the one addition
     * beyond §6.1's type list): a note that cannot be read is not a note, and a
     * note is not a status change.
     */
    public function test_a_note_added_event_requires_a_non_blank_reason(): void
    {
        [$orderId] = $this->orderAndSpareTransaction();

        foreach ([null, '', '   '] as $blank) {
            try {
                $this->recorder()->record(
                    orderId: $orderId,
                    type: OrderEventType::NOTE_ADDED,
                    fromStatus: null,
                    toStatus: null,
                    reason: $blank,
                    transactionId: null,
                    occurredAt: new DateTimeImmutable('2026-09-28 15:30:00'),
                );

                $this->fail('The recorder accepted a note_added event with a blank note.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('non-blank reason', $exception->getMessage());
            }
        }

        $this->assertSame(0, DB::table('order_events')->count());

        // And the real thing, stored verbatim, with no statuses — one row, so
        // the guard above is "refused", not "refused then written anyway".
        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::NOTE_ADDED,
            fromStatus: null,
            toStatus: null,
            reason: 'Courier office asked for the flat number.',
            transactionId: null,
            occurredAt: new DateTimeImmutable('2026-09-28 15:45:00'),
        );

        $row = $this->eventRow($orderId);

        $this->assertSame('note_added', $row->type);
        $this->assertSame('Courier office asked for the flat number.', $row->reason);
        $this->assertNull($row->from_status);
        $this->assertNull($row->to_status);
    }

    public function test_a_note_added_event_may_not_carry_a_status_change(): void
    {
        [$orderId] = $this->orderAndSpareTransaction();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no status change');

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::NOTE_ADDED,
            fromStatus: OrderStatus::PLACED,
            toStatus: OrderStatus::CONFIRMED,
            reason: 'this looks like a transition, not a note',
            transactionId: null,
            occurredAt: new DateTimeImmutable('2026-09-28 16:00:00'),
        );
    }

    /**
     * §11 item 7 — every reason is optional, on every operation. A blank one is
     * stored as NULL, and a real one is stored VERBATIM (untranslated, exactly
     * as typed — §6.1's `reason`), so no trimming or cleaning happens to text a
     * merchant wrote.
     */
    public function test_a_blank_reason_is_stored_as_null_and_a_real_one_verbatim(): void
    {
        [$orderId, $spareTransactionId] = $this->orderAndSpareTransaction();

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::PAYMENT_VOIDED,
            fromStatus: null,
            toStatus: null,
            reason: '   ',
            transactionId: null,
            occurredAt: new DateTimeImmutable('2026-09-28 16:30:00'),
        );

        $this->assertNull($this->eventRow($orderId)->reason);

        DB::table('order_events')->delete();

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::RETURNED,
            fromStatus: null,
            toStatus: null,
            reason: '  върнати 2 бр. — кутията е смачкана  ',
            transactionId: $spareTransactionId,
            occurredAt: new DateTimeImmutable('2026-09-28 16:45:00'),
        );

        $this->assertSame('  върнати 2 бр. — кутията е смачкана  ', $this->eventRow($orderId)->reason);
    }

    /**
     * §6.2's "called inside the caller's transaction, not after it": the event is
     * part of the same fact, so a transition that rolls back leaves no event
     * behind. Rolled back here by the caller, with no participation from the
     * recorder — which is also the proof that it opened no transaction of its own
     * (a commit of its own would have survived this rollback).
     */
    public function test_a_caller_transaction_rolled_back_leaves_no_event(): void
    {
        [$orderId] = $this->orderAndSpareTransaction();
        $this->actingAsPanelStaff();

        $levelBefore = DB::transactionLevel();

        DB::beginTransaction();

        $this->recorder()->record(
            orderId: $orderId,
            type: OrderEventType::STATUS_CHANGED,
            fromStatus: OrderStatus::PLACED,
            toStatus: OrderStatus::CANCELLED,
            reason: 'out of stock',
            transactionId: null,
            occurredAt: new DateTimeImmutable('2026-09-28 17:00:00'),
        );

        $this->assertSame(1, DB::table('order_events')->count(), "the event is written inside the caller's transaction");

        DB::rollBack();

        $this->assertSame(0, DB::table('order_events')->count());
        $this->assertSame(
            $levelBefore,
            DB::transactionLevel(),
            'the recorder must neither open nor close a transaction of its own'
        );
    }
}
