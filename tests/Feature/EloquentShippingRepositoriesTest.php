<?php

namespace Tests\Feature;

use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Exceptions\ShippingClassCodeAlreadyExistsException;
use EasyCo\Shipping\Exceptions\UnknownShippingClassException;
use EasyCo\Shipping\ShippingClass;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentShippingRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    private function classes(): ShippingClassRepository
    {
        return app(ShippingClassRepository::class);
    }

    private function zones(): ShippingZoneRepository
    {
        return app(ShippingZoneRepository::class);
    }

    private function methods(): ShippingMethodRepository
    {
        return app(ShippingMethodRepository::class);
    }

    private function savedClass(string $code, string $name = 'Class'): ShippingClass
    {
        $class = ShippingClass::create($name, $code);
        $this->classes()->save($class);

        return $class;
    }

    private function savedZone(string $name = 'Zone', int $sortOrder = 0): ShippingZone
    {
        $zone = ShippingZone::create($name, $sortOrder, ['BG']);
        $this->zones()->save($zone);

        return $zone;
    }

    private function savedMethod(string $zoneId, string $name, int $sortOrder = 0, bool $isActive = true): ShippingMethod
    {
        $method = ShippingMethod::create($zoneId, $name, ShippingMethodKind::FLAT, $sortOrder, $isActive, 500);
        $this->methods()->save($method);

        return $method;
    }

    // --- ShippingClass ------------------------------------------------------

    public function test_a_shipping_class_round_trips_with_all_fields(): void
    {
        $class = ShippingClass::create('Обемисти', 'obemisti', 'Големи пратки — над 20 кг');
        $this->classes()->save($class);

        $this->assertNotNull($class->id());

        foreach ([$this->classes()->findById($class->id()), $this->classes()->findByCode('obemisti')] as $found) {
            $this->assertNotNull($found);
            $this->assertSame($class->id(), $found->id());
            $this->assertSame('Обемисти', $found->name());
            $this->assertSame('obemisti', $found->code());
            $this->assertSame('Големи пратки — над 20 кг', $found->description());
        }

        $this->assertNull($this->classes()->findByCode('missing'));
        $this->assertNull($this->classes()->findById('999999'));
    }

    public function test_saving_an_existing_class_updates_name_and_description_but_never_the_code(): void
    {
        $class = $this->savedClass('bulky', 'Bulky');
        $class->rename('Oversized');
        $class->describe('Big');
        $this->classes()->save($class);

        $found = $this->classes()->findById($class->id());

        $this->assertSame('Oversized', $found->name());
        $this->assertSame('Big', $found->description());
        $this->assertSame('bulky', $found->code());
        $this->assertSame(1, DB::table('shipping_classes')->count());
    }

    public function test_a_duplicate_class_code_surfaces_as_a_domain_exception(): void
    {
        $this->savedClass('bulky');

        $this->expectException(ShippingClassCodeAlreadyExistsException::class);

        $this->classes()->save(ShippingClass::create('Another', 'bulky'));
    }

    public function test_all_classes_come_back_ordered_by_code(): void
    {
        $this->savedClass('zeta');
        $this->savedClass('alpha');
        $this->savedClass('mid');

        $this->assertSame(['alpha', 'mid', 'zeta'], array_map(fn (ShippingClass $c) => $c->code(), $this->classes()->all()));
    }

    // --- ShippingZone -------------------------------------------------------

    public function test_a_zone_round_trips_with_cyrillic_preserved_in_storage(): void
    {
        $zone = ShippingZone::create('София-град', 2, ['BG', 'RO'], ['София', 'гр. София', 'Пловдив'], ['1000', 'sw1a 1aa']);
        $this->zones()->save($zone);

        $found = $this->zones()->findById($zone->id());

        $this->assertSame('София-град', $found->name());
        $this->assertSame(2, $found->sortOrder());
        $this->assertSame(['BG', 'RO'], $found->countryCodes());
        $this->assertSame(['София', 'гр. София', 'Пловдив'], $found->settlementNames(), 'kept as entered, "гр. София" included');
        $this->assertSame(['1000', 'SW1A1AA'], $found->postcodes(), '"sw1a 1aa" was normalized at construction');

        $raw = (string) DB::table('shipping_zones')->where('id', $zone->id())->value('settlement_names');
        $this->assertStringContainsString('гр. София', $raw, 'stored as Cyrillic, not as \\uXXXX escapes');
        $this->assertStringNotContainsString('\\u', $raw);
        $this->assertSame(['1000', 'SW1A1AA'], json_decode((string) DB::table('shipping_zones')->where('id', $zone->id())->value('postcodes'), true), 'stored normalized (MySQL reformats the JSON text, so it is compared decoded)');
    }

    public function test_a_zone_without_names_or_postcodes_round_trips_as_null(): void
    {
        $zone = ShippingZone::create('Rest', 9, ['DE']);
        $this->zones()->save($zone);

        $found = $this->zones()->findById($zone->id());

        $this->assertNull($found->settlementNames());
        $this->assertNull($found->postcodes());
        $this->assertNull(DB::table('shipping_zones')->where('id', $zone->id())->value('settlement_names'));
        $this->assertNull(DB::table('shipping_zones')->where('id', $zone->id())->value('postcodes'));
    }

    public function test_saving_an_existing_zone_updates_it(): void
    {
        $zone = $this->savedZone('Old', 1);
        $zone->update('Нова', 4, ['GR'], ['Атина'], ['10431']);
        $this->zones()->save($zone);

        $found = $this->zones()->findById($zone->id());

        $this->assertSame('Нова', $found->name());
        $this->assertSame(4, $found->sortOrder());
        $this->assertSame(['GR'], $found->countryCodes());
        $this->assertSame(['Атина'], $found->settlementNames());
        $this->assertSame(['10431'], $found->postcodes());
        $this->assertSame(1, DB::table('shipping_zones')->count());
    }

    public function test_all_ordered_sorts_by_sort_order_then_id_with_equal_sort_orders_resolved_by_id(): void
    {
        $late = $this->savedZone('late', 5);
        $firstTie = $this->savedZone('first tie', 2);
        $early = $this->savedZone('early', 0);
        $secondTie = $this->savedZone('second tie', 2);

        $this->assertSame(
            [$early->id(), $firstTie->id(), $secondTie->id(), $late->id()],
            array_map(fn (ShippingZone $z) => $z->id(), $this->zones()->allOrdered()),
        );
    }

    // --- ShippingMethod -----------------------------------------------------

    public function test_a_per_class_method_round_trips_with_every_field_and_its_rates(): void
    {
        $zone = $this->savedZone('България');
        $this->savedClass('obemisti');
        $this->savedClass('chupliivi');

        $method = ShippingMethod::create(
            $zone->id(),
            'Доставка до адрес',
            ShippingMethodKind::PER_CLASS,
            sortOrder: 3,
            isActive: false,
            amountMinor: 500,
            classRates: ['obemisti' => 1200, 'chupliivi' => 0],
            freeAboveMinor: 15000,
            requiresPickupPoint: true,
        );
        $this->methods()->save($method);

        $found = $this->methods()->findById($method->id());

        $this->assertSame($zone->id(), $found->zoneId());
        $this->assertSame('Доставка до адрес', $found->name());
        $this->assertSame(ShippingMethodKind::PER_CLASS, $found->kind());
        $this->assertSame(3, $found->sortOrder());
        $this->assertFalse($found->isActive());
        $this->assertSame(500, $found->amountMinor());
        $this->assertSame(['chupliivi' => 0, 'obemisti' => 1200], $found->classRates());
        $this->assertSame(15000, $found->freeAboveMinor());
        $this->assertNull($found->carrierCode());
        $this->assertTrue($found->requiresPickupPoint());
    }

    public function test_a_carrier_method_round_trips(): void
    {
        $zone = $this->savedZone();
        $method = ShippingMethod::create($zone->id(), 'Еконт до офис', ShippingMethodKind::CARRIER, freeAboveMinor: 9000, carrierCode: 'econt', requiresPickupPoint: true);
        $this->methods()->save($method);

        $found = $this->methods()->findById($method->id());

        $this->assertSame(ShippingMethodKind::CARRIER, $found->kind());
        $this->assertSame('econt', $found->carrierCode());
        $this->assertNull($found->amountMinor());
        $this->assertSame(9000, $found->freeAboveMinor());
        $this->assertSame([], $found->classRates());
    }

    public function test_a_free_method_round_trips_with_null_money(): void
    {
        $zone = $this->savedZone();
        $method = ShippingMethod::create($zone->id(), 'Безплатна', ShippingMethodKind::FREE);
        $this->methods()->save($method);

        $found = $this->methods()->findById($method->id());

        $this->assertNull($found->amountMinor());
        $this->assertNull($found->freeAboveMinor());
    }

    public function test_for_zone_orders_by_sort_order_then_id_and_equal_sort_orders_resolve_by_id(): void
    {
        $zone = $this->savedZone();
        $other = $this->savedZone('Other');
        $late = $this->savedMethod($zone->id(), 'late', 7);
        $firstTie = $this->savedMethod($zone->id(), 'first tie', 1);
        $early = $this->savedMethod($zone->id(), 'early', 0);
        $secondTie = $this->savedMethod($zone->id(), 'second tie', 1);
        $this->savedMethod($other->id(), 'elsewhere', 0);

        $this->assertSame(
            [$early->id(), $firstTie->id(), $secondTie->id(), $late->id()],
            array_map(fn (ShippingMethod $m) => $m->id(), $this->methods()->forZone($zone->id())),
        );
    }

    public function test_active_only_filters_out_inactive_methods(): void
    {
        $zone = $this->savedZone();
        $active = $this->savedMethod($zone->id(), 'on', 0, true);
        $this->savedMethod($zone->id(), 'off', 1, false);

        $this->assertCount(2, $this->methods()->forZone($zone->id()));
        $this->assertSame([$active->id()], array_map(fn (ShippingMethod $m) => $m->id(), $this->methods()->forZone($zone->id(), activeOnly: true)));
    }

    public function test_for_zone_attaches_each_methods_own_rates(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('a');
        $this->savedClass('b');

        $first = ShippingMethod::create($zone->id(), 'first', ShippingMethodKind::PER_CLASS, 0, true, 100, ['a' => 1]);
        $second = ShippingMethod::create($zone->id(), 'second', ShippingMethodKind::PER_CLASS, 1, true, 100, ['b' => 2]);
        $this->methods()->save($first);
        $this->methods()->save($second);

        [$loadedFirst, $loadedSecond] = $this->methods()->forZone($zone->id());

        $this->assertSame(['a' => 1], $loadedFirst->classRates());
        $this->assertSame(['b' => 2], $loadedSecond->classRates());
    }

    public function test_saving_a_method_replaces_its_rates_and_a_removed_rate_is_really_gone(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('a');
        $this->savedClass('b');
        $this->savedClass('c');

        $method = ShippingMethod::create($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['a' => 1, 'b' => 2]);
        $this->methods()->save($method);

        $method->update('By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['b' => 20, 'c' => 3], null, null, false);
        $this->methods()->save($method);

        $this->assertSame(['b' => 20, 'c' => 3], $this->methods()->findById($method->id())->classRates());
        $this->assertSame(
            ['b', 'c'],
            DB::table('shipping_method_class_rates')->where('method_id', $method->id())->orderBy('class_code')->pluck('class_code')->all(),
            'the removed rate is gone from the table, not merely hidden',
        );

        $method->update('Flat now', ShippingMethodKind::FLAT, 0, true, 100, [], null, null, false);
        $this->methods()->save($method);

        $this->assertSame([], $this->methods()->findById($method->id())->classRates());
        $this->assertSame(0, DB::table('shipping_method_class_rates')->where('method_id', $method->id())->count());
    }

    public function test_a_rate_for_an_unknown_class_code_is_a_domain_exception_and_writes_nothing(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('known');

        $method = ShippingMethod::create($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['known' => 1, 'ghost' => 2]);

        try {
            $this->methods()->save($method);
            $this->fail('the unknown class code should have been refused');
        } catch (UnknownShippingClassException $e) {
            $this->assertStringContainsString('ghost', $e->getMessage());
        }

        $this->assertSame(0, DB::table('shipping_methods')->count(), 'no method row was written');
        $this->assertSame(0, DB::table('shipping_method_class_rates')->count());
        $this->assertNull($method->id());
    }

    public function test_a_failed_save_of_an_existing_method_leaves_its_old_rates_in_place(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('a');

        $method = ShippingMethod::create($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['a' => 1]);
        $this->methods()->save($method);

        $method->update('By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['a' => 9, 'ghost' => 2], null, null, false);

        try {
            $this->methods()->save($method);
            $this->fail('the unknown class code should have been refused');
        } catch (UnknownShippingClassException) {
        }

        $this->assertSame(['a' => 1], $this->methods()->findById($method->id())->classRates());
    }

    public function test_a_method_for_a_nonexistent_zone_surfaces_the_original_query_exception_and_writes_nothing(): void
    {
        $method = ShippingMethod::create('999999', 'Orphan', ShippingMethodKind::FLAT, amountMinor: 500);

        try {
            $this->methods()->save($method);
            $this->fail('a method for a zone that does not exist must be refused');
        } catch (UnknownShippingClassException $e) {
            $this->fail('a zone failure must not be reported as an unknown class: '.$e->getMessage());
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::table('shipping_methods')->count());
        $this->assertSame(0, DB::table('shipping_method_class_rates')->count());
        $this->assertNull($method->id());
    }

    public function test_a_numeric_looking_class_code_round_trips(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('2024', 'Season 2024');
        $this->savedClass('plain');

        $method = ShippingMethod::create($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['2024' => 700, 'plain' => 300]);
        $this->methods()->save($method);

        $found = $this->methods()->findById($method->id());

        $this->assertSame(['2024' => 700, 'plain' => 300], $found->classRates());
        $this->assertSame(700, $found->classRates()['2024']);
        $this->assertSame(
            ['2024', 'plain'],
            DB::table('shipping_method_class_rates')->where('method_id', $method->id())->orderBy('class_code')->pluck('class_code')->map(fn ($c) => (string) $c)->all(),
        );
    }

    public function test_an_unknown_numeric_looking_class_code_is_refused_as_an_unknown_class(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('2024');

        $method = ShippingMethod::create($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['2024' => 700, '2025' => 800]);

        try {
            $this->methods()->save($method);
            $this->fail('the unknown numeric class code should have been refused');
        } catch (UnknownShippingClassException $e) {
            $this->assertStringContainsString('2025', $e->getMessage());
            $this->assertStringNotContainsString('2024', $e->getMessage(), 'only the unknown code is named');
        }

        $this->assertSame(0, DB::table('shipping_methods')->count());
    }

    // --- DB constraints, via raw SQL ---------------------------------------

    public function test_deleting_a_zone_that_has_methods_fails_at_the_database(): void
    {
        $zone = $this->savedZone();
        $this->savedMethod($zone->id(), 'Delivery');

        $this->expectException(QueryException::class);

        DB::table('shipping_zones')->where('id', $zone->id())->delete();
    }

    public function test_deleting_a_class_referenced_by_a_rate_fails_at_the_database(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('bulky');
        $this->methods()->save(ShippingMethod::create($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['bulky' => 9]));

        $this->expectException(QueryException::class);

        DB::table('shipping_classes')->where('code', 'bulky')->delete();
    }

    public function test_renaming_the_code_of_a_class_referenced_by_a_rate_fails_at_the_database(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('bulky');
        $this->methods()->save(ShippingMethod::create($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['bulky' => 9]));

        $this->expectException(QueryException::class);

        DB::table('shipping_classes')->where('code', 'bulky')->update(['code' => 'renamed']);
    }

    public function test_deleting_a_method_removes_its_rates(): void
    {
        $zone = $this->savedZone();
        $this->savedClass('bulky');
        $method = ShippingMethod::create($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, true, 100, ['bulky' => 9]);
        $this->methods()->save($method);

        $this->assertSame(1, DB::table('shipping_method_class_rates')->count());

        DB::table('shipping_methods')->where('id', $method->id())->delete();

        $this->assertSame(0, DB::table('shipping_method_class_rates')->count());
        $this->assertSame(1, DB::table('shipping_classes')->count(), 'the class itself is untouched');
    }

    public function test_the_database_itself_refuses_a_rate_row_for_an_unknown_class(): void
    {
        $zone = $this->savedZone();
        $method = $this->savedMethod($zone->id(), 'Delivery');

        $this->expectException(QueryException::class);

        DB::table('shipping_method_class_rates')->insert(['method_id' => $method->id(), 'class_code' => 'ghost', 'amount_minor' => 1]);
    }
}
