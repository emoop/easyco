<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\OrderPlacementSnapshotWriter;
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
use EasyCo\Order\Persistence\Eloquent\OrderPlacementSnapshotModel;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Shipping stage 4a (shipping-domain-design.md §9.1.4): the order and its write-once placement snapshot STORE the
 * shipping facts — courier, delivery type, carrier service (plus, on the snapshot, the amount, name and code the order
 * already had). Storage only: nothing in checkout writes them yet.
 */
class OrderShippingFactsStorageTest extends TestCase
{
    use RefreshDatabase;

    private function placedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09 12:00:00');
    }

    private function transactionId(string $clientId): string
    {
        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '', clientId: $clientId, priceableId: 'variation-1', status: SaleLineStatus::COMPLETED, quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'), profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-01-01'), effectiveAt: new DateTimeImmutable('2026-01-01'),
            productName: 'Product One', sku: 'SKU-1', regularUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(1000, 'EUR'), promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'), netPaidAmount: Money::fromMinorUnits(1000, 'EUR'), soldAttributes: [],
        ));
        app(TransactionRepository::class)->save($transaction);

        return $transaction->id();
    }

    /** @param array<string, mixed> $override */
    private function order(array $override = []): Order
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        return Order::create(...array_merge([
            'clientId' => $client->id(),
            'transactionId' => $this->transactionId($client->id()),
            'email' => 'buyer@example.com',
            'currency' => 'EUR',
            'subtotal' => Money::fromMinorUnits(1000, 'EUR'),
            'discount' => Money::fromMinorUnits(100, 'EUR'),
            'deliveryType' => OrderDeliveryType::STREET_ADDRESS,
            'recipientName' => 'Ivan Ivanov',
            'phone' => '+359888123456',
            'placedAt' => $this->placedAt(),
            'country' => 'BG',
            'city' => 'Sofia',
            'addressLine1' => 'Vitosha Blvd 1',
            'shipping' => Money::fromMinorUnits(450, 'EUR'),
            'shippingMethodName' => 'To office',
            'shippingMethodCode' => '17',
            'shippingCourier' => 'Econt',
            'shippingDeliveryType' => 'office',
            'shippingServiceCode' => 'std-48h',
        ], $override));
    }

    private function save(Order $order): Order
    {
        app(OrderRepository::class)->save($order);

        return $order;
    }

    // --- round trip -----------------------------------------------------------------------------------------------

    public function test_the_three_shipping_facts_round_trip_on_the_order(): void
    {
        $order = $this->save($this->order());

        $reloaded = app(OrderRepository::class)->findById($order->id());

        $this->assertSame(['Econt', 'office', 'std-48h'], [$reloaded->shippingCourier(), $reloaded->shippingDeliveryType(), $reloaded->shippingServiceCode()]);
        $this->assertSame('To office', $reloaded->shippingMethodName());
        $this->assertSame('17', $reloaded->shippingMethodCode());
        $row = DB::table('orders')->where('id', $order->id())->first();
        $this->assertSame(['Econt', 'office', 'std-48h'], [$row->shipping_courier, $row->shipping_delivery_type, $row->shipping_service_code]);
    }

    public function test_the_snapshot_stores_every_shipping_fact_and_agrees_with_the_order_at_placement(): void
    {
        $order = $this->save($this->order());

        app(OrderPlacementSnapshotWriter::class)->write($order, $this->placedAt());

        $snapshot = OrderPlacementSnapshotModel::where('order_id', $order->id())->sole();
        $this->assertSame($order->shipping()->minorValue(), (int) $snapshot->shipping_minor);
        $this->assertSame(450, (int) $snapshot->shipping_minor);
        $this->assertSame($order->shippingMethodName(), $snapshot->shipping_method_name);
        $this->assertSame($order->shippingMethodCode(), $snapshot->shipping_method_code);
        $this->assertSame($order->shippingCourier(), $snapshot->shipping_courier);
        $this->assertSame($order->shippingDeliveryType(), $snapshot->shipping_delivery_type);
        $this->assertSame($order->shippingServiceCode(), $snapshot->shipping_service_code);
        $this->assertSame($order->total()->minorValue(), (int) $snapshot->total_minor);
        $this->assertSame(
            (int) $snapshot->total_minor,
            (int) $snapshot->subtotal_minor - (int) $snapshot->discount_minor + (int) $snapshot->shipping_minor,
            'the total formula holds on the snapshot too',
        );
    }

    public function test_the_snapshot_still_agrees_with_the_shipping_facts_after_an_edit_changes_the_totals(): void
    {
        $order = $this->save($this->order());
        app(OrderPlacementSnapshotWriter::class)->write($order, $this->placedAt());

        // An edit: reviseTotals() keeps the STORED shipping and bumps the revision; the snapshot is never rewritten.
        $edited = app(OrderRepository::class)->findById($order->id());
        $edited->reviseTotals(Money::fromMinorUnits(2000, 'EUR'), Money::fromMinorUnits(0, 'EUR'), null);
        $edited->bumpEditRevision();
        app(OrderRepository::class)->save($edited);

        $reloaded = app(OrderRepository::class)->findById($order->id());
        $snapshot = OrderPlacementSnapshotModel::where('order_id', $order->id())->sole();

        $this->assertSame(1, $reloaded->editRevision());
        $this->assertSame(2450, $reloaded->total()->minorValue(), 'the edit re-priced the goods, never the shipping');
        $this->assertSame(
            [450, 'To office', '17', 'Econt', 'office', 'std-48h'],
            [(int) $snapshot->shipping_minor, $snapshot->shipping_method_name, $snapshot->shipping_method_code, $snapshot->shipping_courier, $snapshot->shipping_delivery_type, $snapshot->shipping_service_code],
            'the shipping facts of the snapshot are the placement facts, unchanged, and still equal the order\'s own',
        );
        $this->assertSame(
            [$snapshot->shipping_minor, $snapshot->shipping_courier, $snapshot->shipping_delivery_type, $snapshot->shipping_service_code],
            [$reloaded->shipping()->minorValue(), $reloaded->shippingCourier(), $reloaded->shippingDeliveryType(), $reloaded->shippingServiceCode()],
        );
    }

    public function test_the_total_formula_holds_with_shipping_on_both_tables(): void
    {
        $order = $this->save($this->order());
        app(OrderPlacementSnapshotWriter::class)->write($order, $this->placedAt());

        $this->assertSame(1350, $order->total()->minorValue());
        $this->assertSame(1, DB::table('orders')->whereRaw('total_minor = subtotal_minor - discount_minor + shipping_minor')->where('shipping_minor', '>', 0)->count());
        $this->assertSame(1, DB::table('order_placement_snapshots')->whereRaw('total_minor = subtotal_minor - discount_minor + shipping_minor')->where('shipping_minor', '>', 0)->count());
    }

    // --- an order with none ---------------------------------------------------------------------------------------

    public function test_an_order_with_no_shipping_facts_loads_with_nulls_and_its_snapshot_defaults_agree(): void
    {
        $order = $this->save($this->order([
            'shipping' => null, 'shippingMethodName' => null, 'shippingMethodCode' => null,
            'shippingCourier' => null, 'shippingDeliveryType' => null, 'shippingServiceCode' => null,
        ]));
        // A row written the pre-4a way (no shipping columns at all): the table defaults.
        OrderPlacementSnapshotModel::create(array_diff_key(
            app(OrderPlacementSnapshotWriter::class)->rowFor($order, $this->placedAt()),
            array_flip(['shipping_minor', 'shipping_method_name', 'shipping_method_code', 'shipping_courier', 'shipping_delivery_type', 'shipping_service_code']),
        ));

        $reloaded = app(OrderRepository::class)->findById($order->id());
        $snapshot = OrderPlacementSnapshotModel::where('order_id', $order->id())->sole();

        $this->assertSame([null, null, null, null, null], [$reloaded->shippingMethodName(), $reloaded->shippingMethodCode(), $reloaded->shippingCourier(), $reloaded->shippingDeliveryType(), $reloaded->shippingServiceCode()]);
        $this->assertTrue($reloaded->shipping()->isZero());
        $this->assertSame(0, (int) $snapshot->shipping_minor);
        $this->assertNull($snapshot->shipping_courier);
        $this->assertSame($reloaded->total()->minorValue(), (int) $snapshot->total_minor);
    }

    // --- validation: the entity and the database ------------------------------------------------------------------

    public function test_the_entity_refuses_an_invalid_delivery_type_an_overlong_courier_and_facts_without_a_method(): void
    {
        foreach ([
            ['shippingDeliveryType' => 'pigeon'],
            ['shippingDeliveryType' => 'OFFICE'],
            ['shippingCourier' => str_repeat('c', 101)],
            ['shippingCourier' => '   '],
            ['shippingServiceCode' => str_repeat('s', 65)],
            ['shippingMethodName' => null, 'shippingMethodCode' => null, 'shipping' => null],
        ] as $override) {
            try {
                $this->order($override);
                $this->fail('Expected a refusal for '.json_encode($override));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $trimmed = $this->order(['shippingCourier' => '  Econt  ', 'shippingServiceCode' => ' std ']);
        $this->assertSame(['Econt', 'std'], [$trimmed->shippingCourier(), $trimmed->shippingServiceCode()]);
    }

    public function test_the_database_check_refuses_an_unknown_delivery_type_on_both_tables(): void
    {
        $order = $this->save($this->order());
        $snapshotRow = app(OrderPlacementSnapshotWriter::class)->rowFor($order, $this->placedAt());

        try {
            DB::table('orders')->where('id', $order->id())->update(['shipping_delivery_type' => 'pigeon']);
            $this->fail('orders accepted an unknown delivery type');
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1], 'MySQL: check constraint violated');
            $this->assertStringContainsString('ord_ship_delivery_type_check', $e->getMessage());
        }

        try {
            DB::table('order_placement_snapshots')->insert(array_merge($snapshotRow, ['shipping_delivery_type' => 'pigeon', 'created_at' => $this->placedAt()->format('Y-m-d H:i:s')]));
            $this->fail('the snapshot accepted an unknown delivery type');
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1], 'MySQL: check constraint violated');
            $this->assertStringContainsString('ops_ship_delivery_type_check', $e->getMessage());
        }

        foreach (['address', 'office', 'locker', 'other', null] as $allowed) {
            DB::table('orders')->where('id', $order->id())->update(['shipping_delivery_type' => $allowed]);
            $this->assertSame($allowed, DB::table('orders')->where('id', $order->id())->value('shipping_delivery_type'));
        }
    }

    // --- the explicit pin: the method row can change or vanish, the order does not --------------------------------

    private function staff(): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roles);
        $role = $roles->findSystemRoleByName('Administrator');
        $staff = Staff::create('admin-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Admin Tester', $role);
        app(StaffRepository::class)->save($staff);
        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    public function test_renaming_repricing_and_deleting_the_shipping_method_changes_nothing_on_a_stored_order(): void
    {
        $zone = ShippingZone::create('Pin zone', 0, ['BG']);
        app(ShippingZoneRepository::class)->save($zone);
        $method = ShippingMethod::create((string) $zone->id(), 'To office', ShippingMethodKind::FLAT, 0, true, 450, [], null, null, true, ShippingClassMode::REPLACE, 'Econt', ShippingDeliveryType::OFFICE);
        app(ShippingMethodRepository::class)->save($method);

        // The order carries the three snapshot strings; it references the method by id string only (no FK).
        $order = $this->save($this->order(['shippingMethodCode' => (string) $method->id()]));
        app(OrderPlacementSnapshotWriter::class)->write($order, $this->placedAt());
        $this->staff();

        $everything = function () use ($order): array {
            $reloaded = app(OrderRepository::class)->findById($order->id());
            $snapshot = OrderPlacementSnapshotModel::where('order_id', $order->id())->sole()->getAttributes();
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });
            $html = Livewire::test(ViewOrder::class, ['record' => $order->id()])->html();

            return [
                'order' => [$reloaded->shipping()->minorValue(), $reloaded->total()->minorValue(), $reloaded->shippingMethodName(), $reloaded->shippingMethodCode(), $reloaded->shippingCourier(), $reloaded->shippingDeliveryType(), $reloaded->shippingServiceCode()],
                'snapshot' => $snapshot,
                'page' => $html,
                'methodReads' => array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'shipping_methods'))),
            ];
        };

        $before = $everything();
        $this->assertStringContainsString('To office', $before['page']);
        $this->assertStringContainsString('Econt', $before['page']);
        $this->assertSame([], $before['methodReads'], 'the order page never reads the method row');

        // Rename and reprice (through the method's own writer shape: a row update), then DELETE the method.
        DB::table('shipping_methods')->where('id', $method->id())->update(['name' => 'Renamed', 'courier' => 'Speedy', 'delivery_type' => 'locker', 'amount_minor' => 999]);
        $this->assertSame($before['order'], $everything()['order'], 'after rename + reprice');
        DB::table('shipping_methods')->where('id', $method->id())->delete();
        $after = $everything();

        $this->assertSame($before['order'], $after['order']);
        $this->assertSame($before['snapshot'], $after['snapshot']);
        $this->assertSame([], $after['methodReads'], 'the order page and its refund form read no shipping_methods row at all');
        $this->assertStringContainsString('To office', $after['page']);
        $this->assertStringContainsString('Econt', $after['page']);
        $this->assertStringNotContainsString('Renamed', $after['page']);
        // Livewire stamps random 20-character component ids on a page; everything else must be byte-identical.
        $normalize = static fn (string $html): string => preg_replace(
            ['/wire:(id|snapshot|effects)="[^"]*"/', '/\b[A-Za-z0-9]{20}\b/', '/\s+/'],
            ['', 'X', ' '],
            $html,
        );
        $this->assertSame($normalize($before['page']), $normalize($after['page']), 'the rendered order page is identical before the method changed and after it was deleted (Livewire ids aside)');
    }

    // --- the admin page ---------------------------------------------------------------------------------------------

    public function test_the_order_page_shows_courier_and_delivery_type_beside_the_shipping_name_and_price_in_both_languages(): void
    {
        $order = $this->save($this->order());
        $this->staff();

        app()->setLocale('en');
        $en = Livewire::test(ViewOrder::class, ['record' => $order->id()])->html();
        $this->assertStringContainsString('4.50', $en);
        $this->assertStringContainsString('(Econt, To office)', $en);

        app()->setLocale('bg');
        $bg = Livewire::test(ViewOrder::class, ['record' => $order->id()])->html();
        $this->assertStringContainsString('(Econt, До офис)', $bg);
    }

    public function test_the_order_page_adds_nothing_for_an_order_without_courier_or_delivery_type(): void
    {
        $order = $this->save($this->order(['shippingCourier' => null, 'shippingDeliveryType' => null, 'shippingServiceCode' => null]));
        $this->staff();

        $html = Livewire::test(ViewOrder::class, ['record' => $order->id()])->html();

        $this->assertStringContainsString('To office', $html);
        $this->assertStringNotContainsString('(Econt', $html);
        $this->assertStringNotContainsString('До офис', $html);
    }
}
