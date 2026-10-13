<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Address\Address;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\PlacesRegionOrders;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * Checkout RN-1 (checkout-region-and-note-design.md 2.1, 3, 4) in storage: the entity rules of Address and Order (plain validated
 * strings), the note being write-once, the DB CHECKs (rule 2) and the migration (columns, names within 64 characters, an idempotent
 * re-run, down() then up()).
 *
 * The migration is run directly (it has already run on the test database). DDL commits implicitly in MySQL, so tearDown puts the
 * schema back and the fingerprint trait proves the database is unchanged.
 */
class RegionNoteStorageTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const MIGRATION_PATH = 'database/migrations/2026_10_14_000001_add_region_and_customer_note_to_addresses_orders_and_snapshots.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
        $this->setUpRegionOrders();
    }

    protected function tearDown(): void
    {
        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            if (! Schema::hasColumn($table, 'region')) {
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

    private function street(?string $region): Address
    {
        return Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', '+359888111222', null, 'BG', 'Sofia', '1000', 'V 1', null, null, null, null, null, null, null, $region);
    }

    private function order(OrderDeliveryType $type = OrderDeliveryType::STREET_ADDRESS, ?string $region = null, ?string $note = null): Order
    {
        $pickup = $type === OrderDeliveryType::PICKUP_POINT;

        return Order::create(
            clientId: '1', transactionId: '1', email: 'b@example.com', currency: 'EUR', subtotal: Money::fromMinorUnits(1000, 'EUR'), discount: Money::zero('EUR'),
            deliveryType: $type, recipientName: 'I I', phone: '+359888123456', placedAt: new DateTimeImmutable(), country: 'BG',
            city: $pickup ? null : 'Sofia', addressLine1: $pickup ? null : 'V 1',
            carrierCode: $pickup ? 'econt' : null, pickupPointReference: $pickup ? 'office-1' : null, settlement: $pickup ? 'Sofia' : null,
            region: $region, customerNote: $note,
        );
    }

    // ---- Address -----------------------------------------------------------------------------------------------------------------------

    public function test_an_address_takes_a_trimmed_region_for_a_street_address_only(): void
    {
        $this->assertSame('Sofia-city', $this->street('  Sofia-city ')->region());
        $this->assertNull($this->street(null)->region(), 'none is legal: it is optional and an old address predates it');

        $pickup = fn (?string $region) => Address::create(AddressDeliveryType::PICKUP_POINT, 'Ana', '+359888111222', null, 'BG', null, null, null, null, 'econt', 'o-1', 'Sofia', 'N', 'A', null, $region);
        $this->assertNull($pickup(null)->region());

        foreach ([fn () => $pickup('Sofia-city'), fn () => $this->street('   '), fn () => $this->street(str_repeat('я', 101))] as $i => $case) {
            try {
                $case();
                $this->fail("case {$i}: expected an InvalidArgumentException");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(100, mb_strlen((string) $this->street(str_repeat('я', 100))->region()), 'characters, not bytes');
    }

    public function test_an_address_update_replaces_the_region_and_a_refused_update_changes_nothing(): void
    {
        $address = $this->street('Sofia-city');
        $address->update(AddressDeliveryType::STREET_ADDRESS, 'Ana', '+359888111222', 'BG', 'Sofia', '1000', 'V 1', null, null, null, null, null, null, null, 'Plovdiv');
        $this->assertSame('Plovdiv', $address->region());

        try {
            $address->update(AddressDeliveryType::PICKUP_POINT, 'Ana', '+359888111222', 'BG', null, null, null, null, 'econt', 'o-1', 'Sofia', 'N', 'A', null, 'Sofia-city');
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
            $this->assertSame('Plovdiv', $address->region());
        }

        $address->update(AddressDeliveryType::STREET_ADDRESS, 'Ana', '+359888111222', 'BG', 'Sofia', '1000', 'V 1');
        $this->assertNull($address->region(), 'an update without one clears it');
    }

    // ---- Order -----------------------------------------------------------------------------------------------------------------------------

    public function test_an_order_takes_a_region_for_a_street_address_only(): void
    {
        $this->assertSame('Sofia-city', $this->order(OrderDeliveryType::STREET_ADDRESS, ' Sofia-city ')->region());

        foreach ([[OrderDeliveryType::PICKUP_POINT, 'Sofia-city'], [OrderDeliveryType::STREET_ADDRESS, '  '], [OrderDeliveryType::STREET_ADDRESS, str_repeat('x', 101)]] as [$type, $region]) {
            try {
                $this->order($type, $region);
                $this->fail('expected a refusal');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_note_is_kept_exactly_and_refused_when_blank_or_too_long_in_characters(): void
    {
        $note = "Line one\r\nLine two\n  indented — Кирилица";
        $this->assertSame($note, $this->order(note: $note)->customerNote());
        $this->assertSame(1000, mb_strlen((string) $this->order(note: str_repeat('я', 1000))->customerNote()));
        $this->assertNull($this->order()->customerNote());

        foreach (['', '   ', "\r\n \n", str_repeat('я', 1001)] as $bad) {
            try {
                $this->order(note: $bad);
                $this->fail('expected a refusal');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_delivery_revision_replaces_the_region_and_never_touches_the_note(): void
    {
        $order = $this->order(OrderDeliveryType::STREET_ADDRESS, 'Sofia-city', 'Please call first');
        $revise = fn (?string $region, OrderDeliveryType $type = OrderDeliveryType::STREET_ADDRESS) => $order->reviseDelivery(
            $type, 'New Recipient', '+359888123456', 'BG',
            $type === OrderDeliveryType::STREET_ADDRESS ? 'Varna' : null, null, $type === OrderDeliveryType::STREET_ADDRESS ? 'Sea 1' : null, null,
            $type === OrderDeliveryType::STREET_ADDRESS ? null : 'econt', $type === OrderDeliveryType::STREET_ADDRESS ? null : 'o-1', $type === OrderDeliveryType::STREET_ADDRESS ? null : 'Varna',
            region: $region,
        );

        $revise('Varna-region');
        $this->assertSame(['Varna-region', 'Please call first'], [$order->region(), $order->customerNote()]);

        $revise(null);
        $this->assertSame([null, 'Please call first'], [$order->region(), $order->customerNote()], 'an edit without a region clears it; the note is not part of the delivery');

        $revise(null, OrderDeliveryType::PICKUP_POINT);
        $this->assertSame([null, 'Please call first'], [$order->region(), $order->customerNote()], 'a pickup point has none');

        $this->expectException(InvalidArgumentException::class);
        $revise('Varna-region', OrderDeliveryType::PICKUP_POINT);
    }

    // ---- the database is the net (rule 2) ------------------------------------------------------------------------------------------------

    public function test_the_database_refuses_a_pickup_row_with_a_region_a_blank_region_and_a_blank_note(): void
    {
        $this->checkout($this->streetFields('BG', ['region' => 'Sofia-city']), ['customer_note' => 'Hello'])->assertStatus(201);
        $this->checkout($this->pickupFields())->assertStatus(201);

        $street = ['orders' => 'ord', 'order_placement_snapshots' => 'ops'];

        foreach ($street as $table => $prefix) {
            // a street row with a region and a note is fine (the checkout above stored them)
            $this->assertSame('Sofia-city', DB::table($table)->where('delivery_type', 'street_address')->value('region'));

            foreach ([['region' => '   ', "{$prefix}_region_check"], ['customer_note' => ' ', "{$prefix}_customer_note_check"]] as $change) {
                $check = array_pop($change);
                $this->assertRefused(fn () => DB::table($table)->where('delivery_type', 'street_address')->update($change), $check);
            }

            // a pickup row never has a region
            $this->assertRefused(fn () => DB::table($table)->where('delivery_type', 'pickup_point')->update(['region' => 'Sofia-city']), "{$prefix}_region_check");
        }

        $this->assertRefused(fn () => DB::table('addresses')->where('delivery_type', 'street_address')->update(['region' => ' ']), 'addr_region_check');
        $this->assertRefused(fn () => DB::table('addresses')->where('delivery_type', 'pickup_point')->update(['region' => 'Sofia-city']), 'addr_region_check');
    }

    private function assertRefused(callable $write, string $check): void
    {
        try {
            $write();
            $this->fail("expected {$check} to refuse the row");
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1]);
            $this->assertStringContainsString($check, $e->getMessage());
        }
    }

    // ---- the migration -----------------------------------------------------------------------------------------------------------------------

    public function test_every_new_identifier_is_within_64_characters_and_has_the_stated_name(): void
    {
        $migration = $this->migration();

        $names = [];
        foreach ($migration::CHECKS as $checks) {
            $names = [...$names, ...array_keys($checks)];
        }

        sort($names);
        $this->assertSame(['addr_region_check', 'ops_customer_note_check', 'ops_region_check', 'ord_customer_note_check', 'ord_region_check'], $names);

        foreach ([...$names, 'region', 'customer_note'] as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }
    }

    public function test_the_columns_are_nullable_varchars_of_the_stated_length(): void
    {
        $expected = ['addresses' => ['region' => 'varchar(100)'], 'orders' => ['region' => 'varchar(100)', 'customer_note' => 'varchar(1000)'], 'order_placement_snapshots' => ['region' => 'varchar(100)', 'customer_note' => 'varchar(1000)']];

        foreach ($expected as $table => $columns) {
            $present = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($columns as $name => $type) {
                $this->assertTrue($present[$name]['nullable'], "{$table}.{$name}");
                $this->assertSame($type, $present[$name]['type'], "{$table}.{$name}");
            }
        }

        $this->assertFalse(Schema::hasColumn('addresses', 'customer_note'), 'the note is not on an address');
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
            $this->assertFalse(Schema::hasColumn($table, 'region'), $table);
        }

        $this->assertFalse(Schema::hasColumn('orders', 'customer_note'));

        $this->migration()->up();
        $this->assertSame($before, $this->schema(), 'down() then up() gives the same schema');
    }

    public function test_a_partial_state_is_finished_not_failed(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT ord_customer_note_check');
        DB::statement('ALTER TABLE addresses DROP CONSTRAINT addr_region_check');
        DB::statement('ALTER TABLE addresses DROP COLUMN region');

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('addresses', 'region'));
        $names = DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->selectRaw('constraint_name as name')->pluck('name')->all();
        $this->assertContains('ord_customer_note_check', $names);
        $this->assertContains('addr_region_check', $names);
    }

    /** @return array<string, string> each table's CREATE as a SET of lines (re-adding a column puts it last: the order is not the point) */
    private function schema(): array
    {
        $out = [];

        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            $lines = explode("\n", (string) DB::select("SHOW CREATE TABLE {$table}")[0]->{'Create Table'});
            sort($lines);
            $out[$table] = implode("\n", array_map(static fn (string $line): string => rtrim($line, ','), $lines));
        }

        return $out;
    }
}
