<?php

namespace Tests\Feature;

use App\Enums\OrderEventType;
use App\Filament\StaffPanelUser;
use App\Services\OrderAdminReader;
use App\Services\OrderAdminSaleLineView;
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
use Tests\TestCase;

/**
 * order-lifecycle-design.md §6.3, §10 stage 3 — the events half of
 * OrderAdminReader::forOrder(): its order, and its cost.
 *
 * TWO THINGS THIS FILE IS REALLY PINNING, neither of which is the DTO's shape:
 * (1) the list is ordered by occurred_at and then id, so two events recorded
 * inside the same second still read back in write order; (2) the read costs ONE
 * query whatever it returns — five events or twenty-five, the same count (the
 * property admin-panel-design.md §14's D1/D7 discipline exists for).
 *
 * The fixture writes events through the real App\Services\OrderEventRecorder,
 * never a hand-built insert, so the reader is proved against the rows the
 * production writer produces; `occurred_at` is the only fact this file sets
 * itself, and the recorder takes that from its caller anyway.
 */
class OrderAdminReaderEventsTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): OrderAdminReader
    {
        return app(OrderAdminReader::class);
    }

    /**
     * A real order with one real SALE line and nothing else — the same shape the
     * pre-stage-3 baseline query count was measured against, so the arithmetic in
     * the cost test below is a comparison rather than a coincidence.
     *
     * $saleLines > 1 widens ONLY the Lines half of that order, for the stage
     * 7c-1 measurement that asks whether the per-line R7 read is batched: it must
     * not change any other number this file asserts, and it does not, because
     * lines are read in one query and the returns sum in one more.
     */
    private function orderId(int $saleLines = 1): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);

        // $saleLines exists for ONE measurement below (stage 7c-1's batched
        // R7 read for the Lines table): the default of 1 keeps every other
        // test in this file on the exact single-line shape the pre-stage-3
        // baseline query count was measured against. Each line carries its
        // own distinct priceableId, which is all the reader's own thumbnails
        // and per-line mapping key off; the ORDER's own header totals stay
        // at the fixture's original numbers because no test here reads them.
        for ($i = 1; $i <= $saleLines; $i++) {
            $transaction->addSaleLine(SaleLine::create(
                transactionId: '',
                clientId: $client->id(),
                priceableId: "variation-{$i}",
                status: SaleLineStatus::COMPLETED,
                quantity: 1,
                amount: Money::fromMinorUnits(1000, 'EUR'),
                profit: Money::fromMinorUnits(200, 'EUR'),
                recordedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
                effectiveAt: new DateTimeImmutable('2026-09-28 09:00:00'),
                productName: "Product {$i}",
                sku: "SKU-{$i}",
                regularUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
                finalUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
                promotionDiscountShare: Money::zero('EUR'),
                discretionaryDiscount: Money::zero('EUR'),
                netPaidAmount: Money::fromMinorUnits(1000, 'EUR'),
                soldAttributes: [],
            ));
        }

        app(TransactionRepository::class)->save($transaction);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
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

    private function actingAsPanelStaff(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('reader.events@example.com', app(PasswordHasher::class)->hash('password123'), 'Ana Petrova', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /**
     * One event through the real writer. `occurredAt` is passed explicitly
     * because it is the one fact this file's ordering assertions are about.
     */
    private function recordEvent(
        string $orderId,
        OrderEventType $type,
        string $occurredAt,
        ?OrderStatus $from = null,
        ?OrderStatus $to = null,
        ?string $reason = null,
    ): void {
        app(OrderEventRecorder::class)->record(
            orderId: $orderId,
            type: $type,
            fromStatus: $from,
            toStatus: $to,
            reason: $reason,
            transactionId: null,
            occurredAt: new DateTimeImmutable($occurredAt),
        );
    }

    /**
     * A COLD scoped container per measurement, and the reason is not stylistic:
     * OrderAdminReader memoizes its views per instance (AppServiceProvider's
     * scoped() binding), so without forgetting the scoped instances the second
     * measurement would read the first one's cache and count zero.
     */
    private function countForOrderQueries(string $orderId): int
    {
        $this->app->forgetScopedInstances();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->reader()->forOrder($orderId);

        DB::flushQueryLog();

        return $count;
    }

    /**
     * §6.3's read, in full: oldest first, the id breaking a same-second tie, and
     * every stored value carried verbatim — including the two NULL statuses of an
     * event that moved nothing (the timeline must render "no transition" as
     * itself, never as an empty string it invented).
     */
    public function test_events_come_back_oldest_first_with_a_same_second_tie_broken_by_id(): void
    {
        $orderId = $this->orderId();
        $this->actingAsPanelStaff();

        $this->recordEvent($orderId, OrderEventType::STATUS_CHANGED, '2026-09-28 10:00:00', OrderStatus::PLACED, OrderStatus::CONFIRMED, 'customer rang');
        $this->recordEvent($orderId, OrderEventType::PAYMENT_CONFIRMED, '2026-09-28 10:00:05');
        // The SAME second as the previous event: only the id can order these two.
        $this->recordEvent($orderId, OrderEventType::NOTE_ADDED, '2026-09-28 10:00:05', null, null, 'courier asked for the flat number');

        $events = $this->reader()->forOrder($orderId)->events;

        $this->assertCount(3, $events);
        $this->assertSame(
            ['status_changed', 'payment_confirmed', 'note_added'],
            array_map(static fn ($event): string => $event->type, $events),
            'occurred_at orders the list and id breaks a tie inside one second'
        );

        $first = $events[0];

        $this->assertSame('status_changed', $first->type);
        $this->assertSame('placed', $first->fromStatus);
        $this->assertSame('confirmed', $first->toStatus);
        $this->assertSame('customer rang', $first->reason);
        $this->assertNull($first->transactionId);
        $this->assertSame('Ana Petrova', $first->staffName);
        $this->assertSame('2026-09-28 10:00:00', $first->occurredAt->format('Y-m-d H:i:s'));

        $second = $events[1];

        $this->assertNull($second->fromStatus);
        $this->assertNull($second->toStatus);
        $this->assertNull($second->reason);
    }

    /**
     * The state every order placed before this table existed is in, and the shape
     * a stage-7 timeline must render as "nothing recorded yet" rather than as an
     * error: [] — never null.
     */
    public function test_an_order_with_no_events_returns_an_empty_list(): void
    {
        $orderId = $this->orderId();

        $view = $this->reader()->forOrder($orderId);

        $this->assertSame([], $view->events);
    }

    /**
     * §6.3's cost, pinned as a number: ONE events query on top of the 9 queries
     * forOrder() already cost on commit 01398d9 — measured directly with a probe
     * against this exact fixture BEFORE the events read existed (one-line order,
     * no payments, no promotion, nothing else). 9 + 1 = 10.
     *
     * The number is deliberately literal, like OrderAdminReaderTest's own
     * `assertSame(1, $queriesForOptions)`: a future read added to forOrder() must
     * consciously update this line and its comment rather than quietly making the
     * View page more expensive.
     *
     * ORDER-LIFECYCLE STAGE 7c-1 UPDATED IT CONSCIOUSLY, WHICH IS THIS TEST
     * WORKING AS DESIGNED: §8.4's remainingReturnable needed R7's read for the
     * order's lines, and it is ONE grouped query for the whole list (never one
     * per line — EloquentSaleLineRepository::sumQuantityReturnedForOriginatingLines()),
     * so the cost is exactly +1: 9 + 1 (events) + 1 (returns) = 11.
     *
     * ORDER-EDITING STAGE 4A UPDATED IT AGAIN, +2, CONSCIOUSLY: the Lines are
     * now the order's CURRENT lines (OrderCurrentLinesResolver), which adds the
     * EDITED-event read that finds any edit transactions (1) and the grouped
     * edited-away sum (1) — 11 + 2 = 13. Still constant per order: neither is
     * per line. An order
     * with no lines costs no returns query at all (that method returns [] for
     * an empty id list before touching the database), which is why this
     * fixture — one line, like the pre-stage-3 baseline — is the one that sees
     * the +1.
     */
    public function test_for_order_costs_exactly_one_more_query_than_it_did_before_this_stage(): void
    {
        $orderId = $this->orderId();
        $this->actingAsPanelStaff();
        $this->recordEvent($orderId, OrderEventType::PAYMENT_CONFIRMED, '2026-09-28 10:00:00');

        $queries = $this->countForOrderQueries($orderId);

        fwrite(STDERR, "\n[query-count] forOrder(): 9 queries on 01398d9 (no events, no returns read), {$queries} with the events read and stage 7c-1's returns read\n");

        $this->assertSame(13, $queries, 'forOrder() must cost the pre-stage-3 9 queries plus ONE for the events list, ONE for stage 7c-1 s batched returns read, and TWO for stage 4a s current-lines resolution (the EDITED-event read and the batched edited-away sum)');
    }

    /**
     * §6.3 and admin-panel-design.md §14's D15: the cost is one query for the
     * order's history whatever the history's size — 25 events cost what 5 cost,
     * which is the whole reason the reader is allowed to read events at all.
     */
    public function test_five_events_and_twenty_five_events_cost_the_same_number_of_queries(): void
    {
        $fiveOrderId = $this->orderId();
        $twentyFiveOrderId = $this->orderId();

        for ($i = 0; $i < 5; $i++) {
            $this->recordEvent($fiveOrderId, OrderEventType::NOTE_ADDED, '2026-09-28 10:00:0'.$i, null, null, "note {$i}");
        }

        for ($i = 0; $i < 25; $i++) {
            $this->recordEvent($twentyFiveOrderId, OrderEventType::NOTE_ADDED, '2026-09-28 10:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), null, null, "note {$i}");
        }

        $queriesForFive = $this->countForOrderQueries($fiveOrderId);
        $this->assertCount(5, $this->reader()->forOrder($fiveOrderId)->events);

        $queriesForTwentyFive = $this->countForOrderQueries($twentyFiveOrderId);
        $this->assertCount(25, $this->reader()->forOrder($twentyFiveOrderId)->events);

        fwrite(STDERR, "\n[query-count] forOrder(): 5 events = {$queriesForFive} queries, 25 events = {$queriesForTwentyFive} queries\n");

        $this->assertSame(
            $queriesForFive,
            $queriesForTwentyFive,
            'the events read must not grow with the number of events (one query, never a per-event read)'
        );
    }

    /**
     * Stage 7c-1's own version of the same property, for LINES: §8.4's Lines
     * table now needs R7's number on EVERY row (D3's remainingReturnable), which
     * is exactly the shape of read that becomes a query per line if it is not
     * batched. Five lines cost what one line costs.
     *
     * The rows themselves are asserted too — five DISTINCT ids and five SKUs —
     * so the count cannot pass by rendering less than the order holds; and the
     * ABSOLUTE number is asserted as well, not only the equality, because
     * equality alone would still hold if both reads had grown: nine reads for the
     * order and its lines, one for its history, one for the returns summed across
     * its lines.
     */
    public function test_five_lines_cost_the_same_number_of_queries_as_one_line(): void
    {
        $oneLineOrderId = $this->orderId();
        $fiveLineOrderId = $this->orderId(saleLines: 5);

        $queriesForOne = $this->countForOrderQueries($oneLineOrderId);
        $oneLineView = $this->reader()->forOrder($oneLineOrderId);

        $queriesForFive = $this->countForOrderQueries($fiveLineOrderId);
        $fiveLineView = $this->reader()->forOrder($fiveLineOrderId);

        $this->assertCount(1, $oneLineView->lines);
        $this->assertCount(5, $fiveLineView->lines);

        $skus = array_map(static fn (OrderAdminSaleLineView $line): string => $line->sku, $fiveLineView->lines);
        sort($skus);

        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3', 'SKU-4', 'SKU-5'], $skus, 'five real lines, each mapped from its own row');

        $ids = array_map(static fn (OrderAdminSaleLineView $line): string => $line->id, $fiveLineView->lines);

        $this->assertCount(5, array_unique($ids), 'each row is addressed by its own line id, never a blank or shared one');

        $this->assertSame(
            [1],
            array_values(array_unique(array_map(
                static fn (OrderAdminSaleLineView $line): int => $line->remainingReturnable,
                $fiveLineView->lines
            ))),
            'nothing has come back on any line yet, so every row shows its own full quantity'
        );

        fwrite(STDERR, "\n[query-count] forOrder(): 1 line = {$queriesForOne} queries, 5 lines = {$queriesForFive} queries\n");

        $this->assertSame(
            13,
            $queriesForFive,
            'nine reads for the order and its lines, one for its history, one for the returns summed across those lines, and two for the current-lines resolution (EDITED events, edited-away sum)'
        );

        $this->assertSame(
            $queriesForOne,
            $queriesForFive,
            'the per-row R7 number must not turn into a read per row (one grouped query, never a per-line read)'
        );
    }

    /**
     * §6.3: "the reader needs no join to `staff` — staff_name is already on the
     * row". Asserted on the statement itself, because a join would be invisible in
     * the returned DTO while costing exactly what the snapshot column was added to
     * avoid. The assertion is scoped to THE order_events statement on purpose:
     * forOrder()'s pre-existing thumbnail read does join catalog_media
     * (imagePathsFor(), §3.13's own batched read) and is not this stage's business.
     */
    public function test_the_events_read_never_joins_the_staff_table(): void
    {
        $orderId = $this->orderId();
        $this->actingAsPanelStaff();
        $this->recordEvent($orderId, OrderEventType::REFUNDED, '2026-09-28 10:00:00');

        $this->app->forgetScopedInstances();

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $view = $this->reader()->forOrder($orderId);

        DB::flushQueryLog();

        $this->assertSame('Ana Petrova', $view->events[0]->staffName);

        $eventStatements = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select * from `order_events`')
        ));
        // (The resolver's own `select transaction_id from order_events` — stage 4a's
        // EDITED-event read — is a different, narrower statement and is excluded.)

        $this->assertCount(1, $eventStatements, 'the history is exactly ONE query');

        $eventsSql = strtolower($eventStatements[0]);

        $this->assertStringNotContainsString(' join ', $eventsSql, 'the events read joins nothing');
        $this->assertStringNotContainsString('`staff`', $eventsSql, 'the actor comes from the row itself, never from a join to `staff`');
    }
}
