<?php

namespace Tests\Feature;

use App\Services\ShippingMethodCopier;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodReorderer;
use App\Services\ShippingMethodWriter;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Persistence\Eloquent\ShippingMethodModel;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Shipping stage 6a (shipping-domain-design.md section 9.2): the method's destination SCOPE — address | pickup | any — in storage.
 * The repository round trip, the migration's backfill / gate / CHECKs / lossy down(), the legacy `requires_pickup_point` accessor, and
 * the three services that rebuild a method (writer, copier, reorderer) carrying the scope through.
 *
 * The migration is run directly (it has already run on the test database): down() restores the OLD schema so up() has something to
 * migrate. DDL commits implicitly in MySQL, so tearDown restores the schema and removes whatever these tests left behind.
 */
class ShippingDestinationScopeStorageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private const MIGRATION_PATH = 'packages/EasyCo/Shipping/database/migrations/2026_10_09_000005_replace_requires_pickup_point_with_destination_scope.php';

    protected function tearDown(): void
    {
        if (! Schema::hasColumn('shipping_methods', 'destination_scope')) {
            DB::table('shipping_methods')->delete();
            $this->migration()->up();
        }

        DB::table('shipping_methods')->delete();
        DB::table('shipping_zones')->delete();

        parent::tearDown();
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function repository(): ShippingMethodRepository
    {
        return app(ShippingMethodRepository::class);
    }

    private function saved(string $zoneId, string $name, ?ShippingDestinationScope $scope, bool $pickup = false, ?ShippingDeliveryType $label = null, int $sort = 0): ShippingMethod
    {
        $method = ShippingMethod::create($zoneId, $name, ShippingMethodKind::FLAT, $sort, true, 500, [], null, null, $pickup, ShippingClassMode::REPLACE, null, $label, $scope);
        $this->repository()->save($method);

        return $method;
    }

    private function row(string $id): object
    {
        return DB::table('shipping_methods')->where('id', $id)->first();
    }

    private function input(array $overrides = []): ShippingMethodInput
    {
        return new ShippingMethodInput(...array_merge(['name' => 'Courier', 'kind' => 'flat', 'price' => Money::fromMinorUnits(500, 'EUR')], $overrides));
    }

    // --- the repository ------------------------------------------------------------------------------------------------

    public function test_all_three_scopes_round_trip_through_the_repository_and_the_column(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();

        foreach (ShippingDestinationScope::cases() as $i => $scope) {
            $method = $this->saved($zone, 'M'.$i, $scope, sort: $i);

            $this->assertSame($scope->value, $this->row((string) $method->id())->destination_scope);

            $loaded = $this->repository()->findById((string) $method->id());
            $this->assertSame($scope, $loaded->destinationScope());
            $this->assertSame($scope === ShippingDestinationScope::PICKUP, $loaded->requiresPickupPoint());
        }
    }

    public function test_the_legacy_attribute_reads_true_only_for_a_pickup_only_method(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $address = $this->saved($zone, 'A', ShippingDestinationScope::ADDRESS, sort: 0);
        $pickup = $this->saved($zone, 'P', ShippingDestinationScope::PICKUP, sort: 1);
        $any = $this->saved($zone, 'N', ShippingDestinationScope::ANY, sort: 2);

        $this->assertFalse(ShippingMethodModel::findOrFail($address->id())->requires_pickup_point);
        $this->assertTrue(ShippingMethodModel::findOrFail($pickup->id())->requires_pickup_point);
        $this->assertFalse(ShippingMethodModel::findOrFail($any->id())->requires_pickup_point);
        $this->assertTrue(ShippingMethodModel::findOrFail($pickup->id())->toArray()['requires_pickup_point'], 'also in the model array (the admin list reads it)');
    }

    public function test_the_old_column_is_gone_and_the_new_one_is_not_null(): void
    {
        $this->assertFalse(Schema::hasColumn('shipping_methods', 'requires_pickup_point'));
        $column = collect(Schema::getColumns('shipping_methods'))->firstWhere('name', 'destination_scope');
        $this->assertFalse($column['nullable']);
    }

    // --- the CHECKs ---------------------------------------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badRows(): array
    {
        return [
            'an unknown scope' => [['destination_scope' => 'nowhere'], 'ship_methods_scope_check'],
            'label address with scope any' => [['delivery_type' => 'address', 'destination_scope' => 'any'], 'ship_methods_label_scope_check'],
            'label address with scope pickup' => [['delivery_type' => 'address', 'destination_scope' => 'pickup'], 'ship_methods_label_scope_check'],
            'label office with scope address' => [['delivery_type' => 'office', 'destination_scope' => 'address'], 'ship_methods_label_scope_check'],
            'label locker with scope any' => [['delivery_type' => 'locker', 'destination_scope' => 'any'], 'ship_methods_label_scope_check'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRows')]
    public function test_the_database_refuses_a_bad_scope_and_a_bad_label_scope_pair(array $change, string $check): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->saved($zone, 'M', ShippingDestinationScope::ANY);

        try {
            DB::table('shipping_methods')->where('id', $method->id())->update($change);
            $this->fail('the CHECK should have refused '.json_encode($change));
        } catch (QueryException $e) {
            $this->assertSame(3819, (int) $e->errorInfo[1]);
            $this->assertStringContainsString($check, $e->getMessage());
        }

        $this->assertSame('any', $this->row((string) $method->id())->destination_scope, 'nothing was changed');
    }

    public function test_the_database_accepts_every_allowed_pair(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->saved($zone, 'M', ShippingDestinationScope::ANY);

        foreach ([['other', 'address'], ['other', 'pickup'], ['other', 'any'], [null, 'address'], [null, 'pickup'], [null, 'any'], ['address', 'address'], ['office', 'pickup'], ['locker', 'pickup']] as [$label, $scope]) {
            DB::table('shipping_methods')->where('id', $method->id())->update(['delivery_type' => $label, 'destination_scope' => $scope]);
            $this->assertSame($scope, $this->row((string) $method->id())->destination_scope);
        }
    }

    // --- the migration -------------------------------------------------------------------------------------------------------

    private function insertOld(string $zoneId, string $name, int $requires, ?string $label = null): int
    {
        return (int) DB::table('shipping_methods')->insertGetId([
            'zone_id' => $zoneId, 'name' => $name, 'kind' => 'flat', 'sort_order' => 0, 'is_active' => 1, 'amount_minor' => 500,
            'requires_pickup_point' => $requires, 'class_mode' => 'replace', 'delivery_type' => $label, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_up_backfills_the_old_boolean_into_the_scope_and_drops_it(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->migration()->down();

        $this->assertTrue(Schema::hasColumn('shipping_methods', 'requires_pickup_point'));
        $this->assertFalse(Schema::hasColumn('shipping_methods', 'destination_scope'));
        $address = $this->insertOld($zone, 'Home', 0);
        $pickup = $this->insertOld($zone, 'Locker', 1, 'locker');
        $office = $this->insertOld($zone, 'Office', 1, 'office');
        $labelled = $this->insertOld($zone, 'To address', 0, 'address');
        $other = $this->insertOld($zone, 'Other, pickup', 1, 'other');

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('shipping_methods', 'requires_pickup_point'));
        $this->assertSame(
            ['address', 'pickup', 'pickup', 'address', 'pickup'],
            array_map(fn (int $id): string => $this->row((string) $id)->destination_scope, [$address, $pickup, $office, $labelled, $other]),
            'every method keeps its present meaning; nothing becomes any by itself',
        );
    }

    public function test_the_gate_refuses_a_label_that_disagrees_with_the_old_boolean_names_the_ids_and_changes_nothing(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->migration()->down();

        $good = $this->insertOld($zone, 'Fine', 1, 'locker');
        $officeNotPickup = $this->insertOld($zone, 'Office, not pickup', 0, 'office');
        $addressPickup = $this->insertOld($zone, 'Address, pickup', 1, 'address');
        $before = DB::table('shipping_methods')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        try {
            $this->migration()->up();
            $this->fail('the gate should have refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) $officeNotPickup, $e->getMessage());
            $this->assertStringContainsString((string) $addressPickup, $e->getMessage());
            $this->assertStringNotContainsString("shipping_methods.id: {$good}", $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('shipping_methods', 'requires_pickup_point'), 'the old column is untouched');
        $this->assertFalse(Schema::hasColumn('shipping_methods', 'destination_scope'), 'the new column was never added');
        $this->assertSame($before, DB::table('shipping_methods')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());

        // Corrected by hand, the same migration now runs.
        DB::table('shipping_methods')->where('id', $officeNotPickup)->update(['requires_pickup_point' => 1]);
        DB::table('shipping_methods')->where('id', $addressPickup)->update(['requires_pickup_point' => 0]);
        $this->migration()->up();

        $this->assertSame(['pickup', 'pickup', 'address'], array_map(fn (int $id): string => $this->row((string) $id)->destination_scope, [$good, $officeNotPickup, $addressPickup]));
    }

    public function test_up_is_idempotent(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->saved($zone, 'M', ShippingDestinationScope::ANY);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('any', $this->row((string) $method->id())->destination_scope, 'a second run leaves everything as it was');
    }

    public function test_down_restores_the_boolean_and_is_lossy_for_any(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $address = $this->saved($zone, 'A', ShippingDestinationScope::ADDRESS, sort: 0);
        $pickup = $this->saved($zone, 'P', ShippingDestinationScope::PICKUP, sort: 1);
        $any = $this->saved($zone, 'N', ShippingDestinationScope::ANY, sort: 2);

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('shipping_methods', 'destination_scope'));
        $this->assertSame(
            [0, 1, 0],
            array_map(fn (ShippingMethod $m): int => (int) $this->row((string) $m->id())->requires_pickup_point, [$address, $pickup, $any]),
            'pickup -> 1; address AND any -> 0: down() cannot keep "any"',
        );

        $this->migration()->up();

        $this->assertSame(['address', 'pickup', 'address'], array_map(fn (ShippingMethod $m): string => $this->row((string) $m->id())->destination_scope, [$address, $pickup, $any]), 'the any method came back as address-only');
    }

    // --- the services that rebuild a method -----------------------------------------------------------------------------------------

    public function test_the_copier_the_reorderer_and_set_active_carry_the_scope(): void
    {
        $from = (string) $this->zone('From', 0)->id();
        $to = (string) $this->zone('To', 1)->id();
        $any = $this->saved($from, 'Any courier', ShippingDestinationScope::ANY, sort: 0);
        $pickup = $this->saved($from, 'Lockers', ShippingDestinationScope::PICKUP, label: ShippingDeliveryType::LOCKER, sort: 1);

        $result = app(ShippingMethodCopier::class)->copyToZones((string) $any->id(), [$to]);
        $copy = $result->created[0];
        $this->assertSame('any', $this->row((string) $copy->id())->destination_scope, 'a copy keeps any');

        app(ShippingMethodReorderer::class)->moveDown((string) $any->id());
        $this->assertSame('any', $this->row((string) $any->id())->destination_scope, 'a reorder keeps any');
        $this->assertSame('pickup', $this->row((string) $pickup->id())->destination_scope);

        app(ShippingMethodWriter::class)->setActive((string) $any->id(), false);
        $this->assertSame('any', $this->row((string) $any->id())->destination_scope, 'a switch off keeps any');
        $this->assertSame(0, (int) $this->row((string) $any->id())->is_active);
    }

    public function test_the_old_forms_toggle_never_silently_narrows_an_any_method(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $writer = app(ShippingMethodWriter::class);
        $any = $this->saved($zone, 'Any courier', ShippingDestinationScope::ANY);
        $id = (string) $any->id();

        // The form shows the toggle OFF for an any method; saving it untouched (a rename) keeps any.
        $writer->update($id, $this->input(['name' => 'Renamed', 'requiresPickupPoint' => false]));
        $this->assertSame('any', $this->row($id)->destination_scope);

        // Ticking the box is an explicit narrowing to pickup-only; unticking it afterwards is an explicit change to address-only.
        $writer->update($id, $this->input(['name' => 'Renamed', 'requiresPickupPoint' => true]));
        $this->assertSame('pickup', $this->row($id)->destination_scope);
        $writer->update($id, $this->input(['name' => 'Renamed', 'requiresPickupPoint' => false]));
        $this->assertSame('address', $this->row($id)->destination_scope);
    }

    public function test_choosing_the_address_label_on_an_any_method_makes_it_address_only_and_a_new_method_defaults_to_address(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $writer = app(ShippingMethodWriter::class);
        $any = $this->saved($zone, 'Any courier', ShippingDestinationScope::ANY);

        $writer->update((string) $any->id(), $this->input(['deliveryType' => 'address', 'requiresPickupPoint' => false]));
        $this->assertSame('address', $this->row((string) $any->id())->destination_scope, 'the label itself says address-only');

        $created = $writer->create($zone, $this->input(['name' => 'Brand new']));
        $this->assertSame('address', $this->row((string) $created->id())->destination_scope, 'an unticked box on a new method has always meant address-only');

        $pickup = $writer->create($zone, $this->input(['name' => 'Pick', 'requiresPickupPoint' => true]));
        $this->assertSame('pickup', $this->row((string) $pickup->id())->destination_scope);
    }
}
