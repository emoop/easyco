<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * Shipping stage 5g-2a (shipping-domain-design.md 9.3.2 B): the pickup point's KIND in storage — the entity rules of Address and
 * Order (plain validated strings, rule 9), reviseDelivery() keeping the kind for the same office, and the migration (columns, the
 * three CHECKs, identifier lengths, an idempotent re-run, down() and up()). The CHECKs against real rows are in
 * PickupKindCheckoutTest.
 *
 * The migration is run directly (it has already run on the test database). DDL commits implicitly in MySQL, so tearDown puts
 * the schema back and the fingerprint trait proves the database is unchanged.
 */
class PickupKindStorageTest extends TestCase
{
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const MIGRATION_PATH = 'database/migrations/2026_10_13_000001_add_pickup_point_kind_to_addresses_orders_and_snapshots.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            if (! Schema::hasColumn($table, 'pickup_point_kind')) {
                $this->migration()->up();
                break;
            }
        }

        $this->databaseMatchesFingerprint();
        parent::tearDown();
        $this->skipRemigrationIfClean();
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function pickupAddress(?string $kind): Address
    {
        return Address::create(AddressDeliveryType::PICKUP_POINT, 'Ana', '+359888111222', null, 'BG', null, null, null, null, 'econt', 'office-1', 'Sofia', 'Name', 'Addr', $kind);
    }

    private function order(OrderDeliveryType $type, ?string $kind): Order
    {
        $pickup = $type === OrderDeliveryType::PICKUP_POINT;

        return Order::create(
            clientId: '1', transactionId: '1', email: 'b@example.com', currency: 'EUR', subtotal: Money::fromMinorUnits(1000, 'EUR'), discount: Money::zero('EUR'),
            deliveryType: $type, recipientName: 'I I', phone: '+359888123456', placedAt: new DateTimeImmutable(), country: 'BG',
            city: $pickup ? null : 'Sofia', addressLine1: $pickup ? null : 'V 1',
            carrierCode: $pickup ? 'econt' : null, pickupPointReference: $pickup ? 'office-1' : null, settlement: $pickup ? 'Sofia' : null,
            pickupPointKind: $kind,
        );
    }

    // ---- the entities -----------------------------------------------------------------------------------------------

    public function test_an_address_takes_office_locker_or_nothing_for_a_pickup_point(): void
    {
        $this->assertSame('office', $this->pickupAddress('office')->pickupPointKind());
        $this->assertSame('locker', $this->pickupAddress(' locker ')->pickupPointKind(), 'trimmed, like the display text');
        $this->assertNull($this->pickupAddress(null)->pickupPointKind(), 'a historical pickup address has none and is legal');
    }

    public function test_an_address_refuses_a_kind_on_a_street_address_and_any_other_value(): void
    {
        foreach ([
            fn () => Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', '+359888111222', null, 'BG', 'Sofia', addressLine1: 'V 1', pickupPointKind: 'office'),
            fn () => $this->pickupAddress('other'),
            fn () => $this->pickupAddress('address'),
            fn () => $this->pickupAddress('OFFICE'),
            fn () => $this->pickupAddress('nowhere'),
            fn () => $this->pickupAddress('   '),
            fn () => $this->pickupAddress(null)->update(AddressDeliveryType::STREET_ADDRESS, 'Ana', '+359888111222', 'BG', 'Sofia', addressLine1: 'V 1', pickupPointKind: 'office'),
            fn () => $this->pickupAddress(null)->update(AddressDeliveryType::PICKUP_POINT, 'Ana', '+359888111222', 'BG', null, null, null, null, 'econt', 'o-1', 'Sofia', 'N', 'A', 'depot'),
        ] as $i => $case) {
            try {
                $case();
                $this->fail("case {$i}: expected an InvalidArgumentException");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_update_replaces_the_kind_and_a_refused_update_changes_nothing(): void
    {
        $address = $this->pickupAddress('office');
        $address->update(AddressDeliveryType::PICKUP_POINT, 'Ana', '+359888111222', 'BG', null, null, null, null, 'econt', 'office-1', 'Sofia', 'Name', 'Addr', 'locker');
        $this->assertSame('locker', $address->pickupPointKind());

        try {
            $address->update(AddressDeliveryType::PICKUP_POINT, 'Ana', '+359888111222', 'BG', null, null, null, null, 'econt', 'office-1', 'Sofia', 'Name', 'Addr', 'depot');
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
            $this->assertSame('locker', $address->pickupPointKind());
        }
    }

    public function test_an_order_takes_the_kind_for_a_pickup_point_only(): void
    {
        $this->assertSame('locker', $this->order(OrderDeliveryType::PICKUP_POINT, 'locker')->pickupPointKind());
        $this->assertNull($this->order(OrderDeliveryType::PICKUP_POINT, null)->pickupPointKind());

        foreach ([[OrderDeliveryType::STREET_ADDRESS, 'office'], [OrderDeliveryType::PICKUP_POINT, 'other'], [OrderDeliveryType::PICKUP_POINT, 'address']] as [$type, $kind]) {
            try {
                $this->order($type, $kind);
                $this->fail("expected a refusal for {$type->value}/{$kind}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_edit_of_the_delivery_keeps_the_kind_for_the_same_office_and_clears_it_otherwise(): void
    {
        $revise = fn (Order $o, string $reference = 'office-1', OrderDeliveryType $type = OrderDeliveryType::PICKUP_POINT, ?string $kind = null) => $o->reviseDelivery(
            $type, 'New Recipient', '+359888123456', 'BG',
            $type === OrderDeliveryType::STREET_ADDRESS ? 'Sofia' : null, null, $type === OrderDeliveryType::STREET_ADDRESS ? 'V 1' : null, null,
            $type === OrderDeliveryType::STREET_ADDRESS ? null : 'econt', $type === OrderDeliveryType::STREET_ADDRESS ? null : $reference, $type === OrderDeliveryType::STREET_ADDRESS ? null : 'Sofia',
            pickupPointKind: $kind,
        );

        $same = $this->order(OrderDeliveryType::PICKUP_POINT, 'locker');
        $revise($same);
        $this->assertSame('locker', $same->pickupPointKind(), 'an unrelated edit (the same office) does not wipe the kind');

        $other = $this->order(OrderDeliveryType::PICKUP_POINT, 'locker');
        $revise($other, 'office-2');
        $this->assertNull($other->pickupPointKind(), 'another office: the old kind would be wrong');

        $street = $this->order(OrderDeliveryType::PICKUP_POINT, 'locker');
        $revise($street, type: OrderDeliveryType::STREET_ADDRESS);
        $this->assertNull($street->pickupPointKind());

        $named = $this->order(OrderDeliveryType::PICKUP_POINT, 'locker');
        $revise($named, 'office-3', kind: 'office');
        $this->assertSame('office', $named->pickupPointKind(), 'a caller that states a kind wins');

        $bad = $this->order(OrderDeliveryType::PICKUP_POINT, 'locker');
        $this->expectException(InvalidArgumentException::class);
        $revise($bad, kind: 'depot');
    }

    // ---- the migration ---------------------------------------------------------------------------------------------------

    public function test_every_new_identifier_is_within_64_characters_and_has_the_stated_name(): void
    {
        $tables = $this->migration()::TABLES;

        $this->assertSame(['addresses' => 'addr_pickup_kind_check', 'orders' => 'ord_pickup_kind_check', 'order_placement_snapshots' => 'ops_pickup_kind_check'], $tables);

        foreach ([...array_values($tables), 'pickup_point_kind'] as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }
    }

    public function test_the_columns_are_nullable_varchar_10_and_existing_rows_stay_null(): void
    {
        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'pickup_point_kind');
            $this->assertNotNull($column, $table);
            $this->assertTrue($column['nullable'], $table);
            $this->assertSame('varchar(10)', $column['type'], $table);
        }

        // an address saved before this stage (no kind) loads with none
        $address = $this->pickupAddress(null);
        app(AddressRepository::class)->save($address);
        $this->assertNull(app(AddressRepository::class)->findById($address->id())->pickupPointKind());
    }

    public function test_a_second_run_changes_nothing_and_down_then_up_restores_the_schema(): void
    {
        $before = $this->schema();

        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame($before, $this->schema(), 'idempotent up()');

        $this->migration()->down();
        $this->migration()->down();

        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'pickup_point_kind'), $table);
        }

        $names = DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->selectRaw('constraint_name as name')->pluck('name')->all();
        foreach (['addr_pickup_kind_check', 'ord_pickup_kind_check', 'ops_pickup_kind_check'] as $check) {
            $this->assertNotContains($check, $names);
        }

        $this->migration()->up();
        $this->assertSame($before, $this->schema(), 'down() then up() gives the same schema');
    }

    public function test_a_partial_state_is_finished_not_failed(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT ord_pickup_kind_check');
        DB::statement('ALTER TABLE addresses DROP CONSTRAINT addr_pickup_kind_check');
        DB::statement('ALTER TABLE addresses DROP COLUMN pickup_point_kind');

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('addresses', 'pickup_point_kind'));
        $this->assertTrue(DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', 'orders')->where('constraint_name', 'ord_pickup_kind_check')->exists());
    }

    /** @return array<string, string> */
    private function schema(): array
    {
        $out = [];

        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            // Compared as a SET of lines: dropping and re-adding a column puts it last, and since checkout RN-1 other columns follow
            // `pickup_point_kind`. The columns and CHECKs must be identical; their order is not the point.
            $lines = explode("
", (string) DB::select("SHOW CREATE TABLE {$table}")[0]->{'Create Table'});
            sort($lines);
            $out[$table] = implode("
", array_map(static fn (string $line): string => rtrim($line, ','), $lines));
        }

        return $out;
    }
}
