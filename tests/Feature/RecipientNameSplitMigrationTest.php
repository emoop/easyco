<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\PlacesRegionOrders;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * Checkout form v2, stage C1d-A (checkout-form-v2-design.md section 2.2): `recipient_name` becomes `recipient_first_name` +
 * `recipient_last_name` on addresses, orders and order_placement_snapshots, with a BACKFILL that splits at the LAST run of whitespace,
 * two CHECKs per table (rule 2), an idempotent up() / down(), and a down() that gives the single name back.
 *
 * The two migrations are run directly (they have already run on the test database). DDL commits implicitly in MySQL, so tearDown puts
 * the schema back and the fingerprint trait proves the database is unchanged.
 */
class RecipientNameSplitMigrationTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const ADDRESS_MIGRATION = 'packages/EasyCo/Address/database/migrations/2026_10_15_000001_add_recipient_first_and_last_name_to_addresses.php';

    private const ORDER_MIGRATION = 'packages/EasyCo/Order/database/migrations/2026_10_15_000001_add_recipient_first_and_last_name_to_orders_and_snapshots.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRegionOrders();
        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            if (! Schema::hasColumn($table, 'recipient_first_name')) {
                $this->address()->up();
                $this->orders()->up();
                break;
            }
        }

        $this->databaseMatchesFingerprint();
        parent::tearDown();
        $this->skipRemigrationIfClean();
    }

    private function address(): Migration
    {
        return require base_path(self::ADDRESS_MIGRATION);
    }

    private function orders(): Migration
    {
        return require base_path(self::ORDER_MIGRATION);
    }

    private function down(): void
    {
        $this->orders()->down();
        $this->address()->down();
    }

    private function up(): void
    {
        $this->address()->up();
        $this->orders()->up();
    }

    /** @return array<string, array{string, string, string}> old name => [first, last] */
    public static function splits(): array
    {
        return [
            'two words' => ['Сия Иванова', 'Сия', 'Иванова'],
            'three words: the family name is the LAST one' => ['Иван Петров Иванов', 'Иван Петров', 'Иванов'],
            'one word: all first name, last name empty' => ['Мадона', 'Мадона', ''],
            'leading and trailing spaces' => ['   Ana Popova  ', 'Ana', 'Popova'],
            'several spaces: the split is at the LAST run' => ["  Ana   Maria \t  Popova ", 'Ana   Maria', 'Popova'],
            'a tab between' => ["Ivan\tIvanov", 'Ivan', 'Ivanov'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('splits')]
    public function test_the_split_rule_for_both_migrations(string $old, string $first, string $last): void
    {
        $this->assertSame([$first, $last], $this->address()::split($old));
        $this->assertSame([$first, $last], $this->orders()::split($old));
    }

    public function test_a_part_longer_than_100_characters_is_cut_to_the_new_column(): void
    {
        [$first, $last] = $this->orders()::split(str_repeat('я', 150).' '.str_repeat('ю', 120));

        $this->assertSame([100, 100], [mb_strlen($first), mb_strlen($last)]);
    }

    public function test_the_backfill_of_real_rows_in_the_three_tables_and_the_down_round_trip(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $this->assertSame(1, DB::table('order_placement_snapshots')->count());

        // Back to the old shape, as it was before this stage: rows carry ONE name.
        $this->down();
        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'recipient_name'), $table);
            $this->assertFalse(Schema::hasColumn($table, 'recipient_first_name'), $table);
        }
        $this->assertSame('Guest Buyer', DB::table('orders')->value('recipient_name'), 'down() gives first + " " + last back');

        DB::table('orders')->update(['recipient_name' => ' Иван Петров Иванов ']);
        DB::table('order_placement_snapshots')->update(['recipient_name' => 'Мадона']);
        foreach (self::splits() as [$old]) {
            DB::table('addresses')->insert([
                'delivery_type' => 'street_address', 'recipient_name' => $old, 'phone' => '+359888111999', 'country' => 'BG', 'city' => 'Sofia',
                'address_line_1' => 'V 1', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->up();

        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'recipient_name'), "{$table}: the old column is dropped");
            $this->assertTrue(Schema::hasColumn($table, 'recipient_first_name'), $table);
        }
        $this->assertSame(['Иван Петров', 'Иванов'], [DB::table('orders')->value('recipient_first_name'), DB::table('orders')->value('recipient_last_name')]);
        $this->assertSame(['Мадона', ''], [DB::table('order_placement_snapshots')->value('recipient_first_name'), DB::table('order_placement_snapshots')->value('recipient_last_name')]);

        $stored = DB::table('addresses')->where('phone', '+359888111999')->orderBy('id')->get()->map(fn ($row) => [$row->recipient_first_name, $row->recipient_last_name])->all();
        $this->assertSame(array_map(fn (array $case): array => [$case[1], $case[2]], array_values(self::splits())), $stored);

        // A second run changes nothing (rule 6); down() and up() again give the same rows back.
        $this->up();
        $this->assertSame($stored, DB::table('addresses')->where('phone', '+359888111999')->orderBy('id')->get()->map(fn ($row) => [$row->recipient_first_name, $row->recipient_last_name])->all());

        $this->down();
        $this->assertSame('Ana   Maria Popova', DB::table('addresses')->where('phone', '+359888111999')->orderBy('id')->get()->get(4)->recipient_name);
        $this->assertSame('Мадона', DB::table('addresses')->where('phone', '+359888111999')->orderBy('id')->get()->get(2)->recipient_name, 'a single word comes back as it was');
        $this->up();
    }

    public function test_the_new_columns_are_not_null_varchar_100_with_no_default_and_the_checks_are_short_and_named(): void
    {
        foreach (['addresses', 'orders', 'order_placement_snapshots'] as $table) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');

            foreach (['recipient_first_name', 'recipient_last_name'] as $column) {
                $this->assertFalse($columns[$column]['nullable'], "{$table}.{$column}");
                $this->assertSame('varchar(100)', $columns[$column]['type'], "{$table}.{$column}");
                $this->assertNull($columns[$column]['default'], "{$table}.{$column}: a NEW row can never skip the name");
            }
        }

        $expected = [
            'addresses' => ['addr_first_name_check', 'addr_pickup_ident_check'],
            'orders' => ['ord_first_name_check', 'ord_pickup_ident_check'],
            'order_placement_snapshots' => ['ops_first_name_check', 'ops_pickup_ident_check'],
        ];

        foreach ($expected as $table => $names) {
            foreach ($names as $name) {
                $this->assertLessThanOrEqual(64, strlen($name));
                $this->assertTrue(DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('constraint_name', $name)->where('constraint_type', 'CHECK')->exists(), "{$table}.{$name}");
            }
        }
    }

    public function test_the_database_itself_refuses_a_blank_first_name_and_a_pickup_row_with_neither_a_reference_nor_a_description(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $this->checkout($this->pickupFields(['pickup_point_reference' => null, 'pickup_point_name' => null]))->assertStatus(201);
        $this->assertSame(2, DB::table('orders')->count());

        foreach (['orders' => 'ord', 'order_placement_snapshots' => 'ops'] as $table => $prefix) {
            $this->assertRefused(fn () => DB::table($table)->where('delivery_type', 'street_address')->update(['recipient_first_name' => '   ']), "{$prefix}_first_name_check");
            // The empty LAST name stays legal (a row from before the split).
            DB::table($table)->where('delivery_type', 'street_address')->update(['recipient_last_name' => '']);
            $this->assertRefused(fn () => DB::table($table)->where('delivery_type', 'pickup_point')->update(['pickup_point_address' => null]), "{$prefix}_pickup_ident_check");
            // A reference alone (an identified point) is legal: no description needed.
            DB::table($table)->where('delivery_type', 'pickup_point')->update(['pickup_point_reference' => 'office-9', 'pickup_point_address' => null]);
        }

        $this->assertRefused(fn () => DB::table('addresses')->insert([
            'delivery_type' => 'street_address', 'recipient_first_name' => ' ', 'recipient_last_name' => 'X', 'phone' => '1', 'country' => 'BG', 'city' => 'S', 'address_line_1' => 'V',
            'created_at' => now(), 'updated_at' => now(),
        ]), 'addr_first_name_check');
        $this->assertRefused(fn () => DB::table('addresses')->insert([
            'delivery_type' => 'pickup_point', 'recipient_first_name' => 'A', 'recipient_last_name' => 'B', 'phone' => '1', 'country' => 'BG', 'carrier_code' => 'econt', 'settlement' => 'Sofia',
            'created_at' => now(), 'updated_at' => now(),
        ]), 'addr_pickup_ident_check');
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
}
