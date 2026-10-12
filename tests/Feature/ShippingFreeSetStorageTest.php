<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\ShippingMethodCopier;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodReorderer;
use App\Services\ShippingMethodWriter;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * Shipping stage 5g-1 (shipping-domain-design.md 9.3.2 A, 9.3.9): the free set in storage — the three columns and their defaults,
 * the two CHECKs, the backfill (Q1) and an idempotent re-run, the repository round trip, and the admin input path (the owner's
 * default for a new method, the stored set kept on an update, copier and reorderer carrying it).
 *
 * The migration is run directly (it has already run on the test database). DDL commits implicitly in MySQL, so tearDown puts
 * the schema back.
 */
class ShippingFreeSetStorageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const MIGRATION_PATH = 'packages/EasyCo/Shipping/database/migrations/2026_10_12_000001_add_free_destinations_to_shipping_methods_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        if (! Schema::hasColumn('shipping_methods', 'free_for_office')) {
            DB::table('shipping_methods')->delete();
            $this->migration()->up();
        }

        DB::table('shipping_methods')->delete();
        DB::table('shipping_zones')->delete();

        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function repository(): ShippingMethodRepository
    {
        return app(ShippingMethodRepository::class);
    }

    private function row(string $id): object
    {
        return DB::table('shipping_methods')->where('id', $id)->first();
    }

    /** @return list<string> the three flags of a row as 'address,office,locker' kinds that are on */
    private function flags(string $id): array
    {
        $row = $this->row($id);

        return array_values(array_filter([
            $row->free_for_address ? 'address' : null,
            $row->free_for_office ? 'office' : null,
            $row->free_for_locker ? 'locker' : null,
        ]));
    }

    /** @param list<Type>|null $free */
    private function saved(string $zoneId, string $name, Scope $scope = Scope::ANY, ?Type $label = null, ?int $freeAbove = null, ?array $free = null, int $sort = 0): ShippingMethod
    {
        $method = ShippingMethod::create($zoneId, $name, ShippingMethodKind::FLAT, $sort, true, 500, [], $freeAbove, null, false, ShippingClassMode::REPLACE, null, $label, $scope, $free);
        $this->repository()->save($method);

        return $method;
    }

    private function input(array $overrides = []): ShippingMethodInput
    {
        return new ShippingMethodInput(...array_merge(['name' => 'Courier', 'kind' => 'flat', 'price' => Money::fromMinorUnits(500, 'EUR'), 'freeAbove' => Money::fromMinorUnits(6000, 'EUR')], $overrides));
    }

    // --- identifiers (CLAUDE.md rule 5) ------------------------------------------------------------------------------

    public function test_every_new_identifier_is_within_64_characters_and_has_the_stated_name(): void
    {
        $migration = $this->migration();

        $this->assertSame('ship_methods_free_flags_check', $migration::FLAGS_CHECK);
        $this->assertSame('ship_methods_free_served_check', $migration::SERVED_CHECK);

        foreach ([$migration::FLAGS_CHECK, $migration::SERVED_CHECK, 'free_for_address', 'free_for_office', 'free_for_locker'] as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }
    }

    public function test_the_columns_are_not_null_booleans_with_the_owners_defaults(): void
    {
        $columns = collect(Schema::getColumns('shipping_methods'))->keyBy('name');

        foreach (['free_for_address' => '0', 'free_for_office' => '1', 'free_for_locker' => '1'] as $name => $default) {
            $this->assertFalse($columns[$name]['nullable'], $name);
            $this->assertSame('tinyint(1)', $columns[$name]['type'], $name);
            $this->assertSame($default, trim((string) $columns[$name]['default'], "'"), $name);
        }

        $zone = (string) $this->zone('Z', 0)->id();
        $id = DB::table('shipping_methods')->insertGetId([
            'zone_id' => $zone, 'name' => 'Raw', 'kind' => 'flat', 'sort_order' => 0, 'is_active' => 1, 'amount_minor' => 500,
            'class_mode' => 'replace', 'destination_scope' => 'any', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(['office', 'locker'], $this->flags((string) $id), 'a raw insert gets the default: office + locker');
    }

    // --- the repository ------------------------------------------------------------------------------------------------

    public function test_the_set_round_trips_through_the_repository_and_the_columns(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $cases = [
            'locker only' => [[Type::LOCKER], ['locker']],
            'address and office' => [[Type::ADDRESS, Type::OFFICE], ['address', 'office']],
            'all three' => [[Type::LOCKER, Type::OFFICE, Type::ADDRESS], ['address', 'office', 'locker']],
        ];

        $i = 0;

        foreach ($cases as $label => [$set, $expected]) {
            $method = $this->saved($zone, $label, free: $set, freeAbove: 6000, sort: $i++);
            $this->assertSame($expected, $this->flags((string) $method->id()), $label);
            $this->assertEquals($set === [Type::LOCKER, Type::OFFICE, Type::ADDRESS] ? Type::destinationKinds() : $set, $this->repository()->findById((string) $method->id())->freeDestinations(), $label);
        }
    }

    public function test_a_method_saved_with_no_stated_set_stores_every_kind_it_serves(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $any = $this->saved($zone, 'Any');
        $office = $this->saved($zone, 'Office', Scope::PICKUP, Type::OFFICE, sort: 1);

        $this->assertSame(['address', 'office', 'locker'], $this->flags((string) $any->id()));
        $this->assertSame(['office'], $this->flags((string) $office->id()));
    }

    // --- the CHECKs (the database is the net, rule 2) -------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badRows(): array
    {
        return [
            'a flag of 2' => [['free_for_office' => 2], 'ship_methods_free_flags_check'],
            'a negative flag' => [['free_for_locker' => -1], 'ship_methods_free_flags_check'],
            'a threshold with no free kind' => [['free_above_minor' => 6000, 'free_for_address' => 0, 'free_for_office' => 0, 'free_for_locker' => 0], 'ship_methods_free_served_check'],
            'a threshold whose only free kind is not served (address-only, office+locker)' => [['free_above_minor' => 6000, 'destination_scope' => 'address', 'free_for_address' => 0, 'free_for_office' => 1, 'free_for_locker' => 1], 'ship_methods_free_served_check'],
            'a threshold on a locker-only method whose only free kind is office' => [['free_above_minor' => 6000, 'destination_scope' => 'pickup', 'delivery_type' => 'locker', 'free_for_address' => 0, 'free_for_office' => 1, 'free_for_locker' => 0], 'ship_methods_free_served_check'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRows')]
    public function test_the_database_refuses_a_bad_flag_and_a_threshold_that_can_never_apply(array $change, string $check): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->saved($zone, 'M');

        try {
            DB::table('shipping_methods')->where('id', $method->id())->update($change);
            $this->fail("expected {$check} to refuse the row");
        } catch (QueryException $e) {
            $this->assertStringContainsString($check, $e->getMessage());
        }
    }

    public function test_the_database_accepts_a_threshold_with_a_served_free_kind_and_an_empty_set_without_a_threshold(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->saved($zone, 'M');
        $id = (string) $method->id();

        DB::table('shipping_methods')->where('id', $id)->update(['free_above_minor' => 6000, 'free_for_address' => 0, 'free_for_office' => 0, 'free_for_locker' => 1]);
        DB::table('shipping_methods')->where('id', $id)->update(['free_above_minor' => null, 'free_for_address' => 0, 'free_for_office' => 0, 'free_for_locker' => 0]);
        DB::table('shipping_methods')->where('id', $id)->update(['free_above_minor' => 6000, 'destination_scope' => 'address', 'free_for_address' => 1, 'free_for_office' => 0, 'free_for_locker' => 0]);

        $this->assertSame(['address'], $this->flags($id));
    }

    // --- the migration: backfill, idempotence, down -----------------------------------------------------------------------

    private function insertRaw(string $zoneId, string $name, string $scope, ?string $label, ?int $freeAbove = 6000): int
    {
        return (int) DB::table('shipping_methods')->insertGetId([
            'zone_id' => $zoneId, 'name' => $name, 'kind' => 'flat', 'sort_order' => 0, 'is_active' => 1, 'amount_minor' => 500,
            'free_above_minor' => $freeAbove, 'class_mode' => 'replace', 'destination_scope' => $scope, 'delivery_type' => $label,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_backfill_gives_existing_methods_office_and_locker_limited_to_what_they_serve(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('shipping_methods', 'free_for_office'));

        $ids = [
            'any' => $this->insertRaw($zone, 'Any', 'any', null),
            'pickup' => $this->insertRaw($zone, 'Pickup', 'pickup', null),
            'address scope' => $this->insertRaw($zone, 'Address', 'address', null),
            'label address' => $this->insertRaw($zone, 'Labelled address', 'address', 'address'),
            'label office' => $this->insertRaw($zone, 'Office', 'pickup', 'office'),
            'label locker' => $this->insertRaw($zone, 'Locker', 'pickup', 'locker'),
            'label other, any' => $this->insertRaw($zone, 'Other', 'any', 'other'),
            'no threshold, address' => $this->insertRaw($zone, 'No threshold', 'address', null, null),
        ];

        $this->migration()->up();

        $this->assertSame(
            [
                'any' => ['office', 'locker'],
                'pickup' => ['office', 'locker'],
                'address scope' => ['address'],
                'label address' => ['address'],
                'label office' => ['office'],
                'label locker' => ['locker'],
                'label other, any' => ['office', 'locker'],
                'no threshold, address' => ['address'],
            ],
            array_map(fn (int $id): array => $this->flags((string) $id), $ids),
            'the owner default office + locker, refined so no threshold is left that cannot apply',
        );

        // And every one of them loads through the entity (its invariant holds for the migrated data).
        foreach ($ids as $id) {
            $this->assertNotNull($this->repository()->findById((string) $id));
        }
    }

    public function test_a_second_run_changes_nothing_and_never_overwrites_a_merchants_choice(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->saved($zone, 'M', freeAbove: 6000, free: [Type::LOCKER]);
        $id = (string) $method->id();
        $before = DB::select('SHOW CREATE TABLE shipping_methods')[0]->{'Create Table'};

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(['locker'], $this->flags($id), 'the merchant chose locker only; a re-run keeps it');
        $this->assertSame($before, DB::select('SHOW CREATE TABLE shipping_methods')[0]->{'Create Table'}, 'the schema is identical after two more runs');
    }

    public function test_down_removes_the_checks_and_the_columns_and_is_itself_idempotent(): void
    {
        $this->migration()->down();
        $this->migration()->down();

        foreach (['free_for_address', 'free_for_office', 'free_for_locker'] as $column) {
            $this->assertFalse(Schema::hasColumn('shipping_methods', $column), $column);
        }

        $checks = DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', 'shipping_methods')->selectRaw('constraint_name as name')->pluck('name')->all();
        $this->assertNotContains('ship_methods_free_flags_check', $checks);
        $this->assertNotContains('ship_methods_free_served_check', $checks);

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('shipping_methods', 'free_for_locker'));
    }

    public function test_a_partial_state_is_finished_not_failed(): void
    {
        // As after an interrupted run (non-transactional DDL): the columns exist but a CHECK does not.
        DB::statement('ALTER TABLE shipping_methods DROP CONSTRAINT ship_methods_free_served_check');

        $this->migration()->up();

        $this->assertTrue(DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', 'shipping_methods')->where('constraint_name', 'ship_methods_free_served_check')->exists());
    }

    // --- the admin input path --------------------------------------------------------------------------------------------

    public function test_a_new_method_from_the_admin_gets_the_owners_default_office_and_locker(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $writer = app(ShippingMethodWriter::class);

        $any = $writer->create($zone, $this->input(['name' => 'Any', 'destinationScope' => 'any']));
        $this->assertSame(['office', 'locker'], $this->flags((string) $any->id()));

        $pickup = $writer->create($zone, $this->input(['name' => 'Pickup', 'destinationScope' => 'pickup']));
        $this->assertSame(['office', 'locker'], $this->flags((string) $pickup->id()));

        $address = $writer->create($zone, $this->input(['name' => 'Address', 'destinationScope' => 'address']));
        $this->assertSame(['address'], $this->flags((string) $address->id()), 'an address-only method: office + locker would leave nothing, so the served kind');

        $office = $writer->create($zone, $this->input(['name' => 'Office', 'destinationScope' => 'pickup', 'deliveryType' => 'office']));
        $this->assertSame(['office'], $this->flags((string) $office->id()));

        $oldToggle = $writer->create($zone, $this->input(['name' => 'Old toggle', 'requiresPickupPoint' => true]));
        $this->assertSame(['office', 'locker'], $this->flags((string) $oldToggle->id()));
    }

    public function test_an_explicit_set_is_honoured_and_a_bad_one_is_a_field_error(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $writer = app(ShippingMethodWriter::class);

        $locker = $writer->create($zone, $this->input(['name' => 'Locker only', 'destinationScope' => 'any', 'freeDestinations' => ['locker']]));
        $this->assertSame(['locker'], $this->flags((string) $locker->id()));

        foreach ([['other'], ['nowhere'], [5]] as $bad) {
            try {
                $writer->create($zone, $this->input(['name' => 'Bad', 'freeDestinations' => $bad]));
                $this->fail('expected a field error');
            } catch (ShippingMethodInvalidException $e) {
                $this->assertArrayHasKey('free_destinations', $e->errors);
            }
        }

        // a set that cannot apply to what the method serves is the entity's refusal, shown on the threshold field
        try {
            $writer->create($zone, $this->input(['name' => 'Dead threshold', 'destinationScope' => 'address', 'freeDestinations' => ['locker']]));
            $this->fail('expected a refusal');
        } catch (ShippingMethodInvalidException $e) {
            $this->assertArrayHasKey('free_destinations', $e->errors);
        }
    }

    public function test_an_update_without_a_set_keeps_the_stored_one_and_narrowing_replaces_a_set_that_no_longer_applies(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $writer = app(ShippingMethodWriter::class);
        $method = $this->saved($zone, 'M', freeAbove: 6000, free: [Type::LOCKER]);
        $id = (string) $method->id();

        $writer->update($id, $this->input(['name' => 'Renamed', 'destinationScope' => 'any']));
        $this->assertSame(['locker'], $this->flags($id), 'the old form sends no set: the stored one stays');

        $writer->update($id, $this->input(['name' => 'Renamed', 'destinationScope' => 'address']));
        $this->assertSame(['address'], $this->flags($id), 'narrowed to address only: locker no longer applies, so the owner default for what it serves');
    }

    public function test_an_update_with_a_set_replaces_it_and_the_audit_snapshot_shows_it(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->saved($zone, 'M', freeAbove: 6000, free: [Type::OFFICE, Type::LOCKER]);
        $id = (string) $method->id();

        app(ShippingMethodWriter::class)->update($id, $this->input(['name' => 'M', 'destinationScope' => 'any', 'freeDestinations' => ['address']]));

        $this->assertSame(['address'], $this->flags($id));
        $this->assertSame(['address'], ShippingMethodWriter::snapshot($this->repository()->findById($id))['free_destinations']);
    }

    public function test_copier_reorderer_and_set_active_carry_the_set(): void
    {
        $from = (string) $this->zone('From', 0)->id();
        $to = (string) $this->zone('To', 1)->id();
        $locker = $this->saved($from, 'Locker free', freeAbove: 6000, free: [Type::LOCKER], sort: 0);
        $this->saved($from, 'Other', freeAbove: 6000, free: [Type::ADDRESS], sort: 1);

        $copy = app(ShippingMethodCopier::class)->copyToZones((string) $locker->id(), [$to])->created[0];
        $this->assertSame(['locker'], $this->flags((string) $copy->id()), 'a copy keeps the set');

        app(ShippingMethodReorderer::class)->moveDown((string) $locker->id());
        $this->assertSame(['locker'], $this->flags((string) $locker->id()), 'a reorder keeps the set');

        app(ShippingMethodWriter::class)->setActive((string) $locker->id(), false);
        $this->assertSame(['locker'], $this->flags((string) $locker->id()), 'a switch off keeps the set');
    }
}
