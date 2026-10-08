<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\Exceptions\ShippingMethodNotFoundException;
use App\Services\ShippingMethodCopier;
use App\Services\ShippingMethodReorderer;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5d (shipping-domain-design.md §12.3.3, §12.5, §12.6): moving a method inside its zone, and copying a method
 * into other zones. Both are one transaction, with audit entries and hooks after the commit.
 */
class ShippingMethodReordererAndCopierTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function reorderer(): ShippingMethodReorderer
    {
        return app(ShippingMethodReorderer::class);
    }

    private function copier(): ShippingMethodCopier
    {
        return app(ShippingMethodCopier::class);
    }

    private function methodAt(string $zoneId, string $name, int $sortOrder, bool $active = true): ShippingMethod
    {
        $method = ShippingMethod::create($zoneId, $name, ShippingMethodKind::FLAT, $sortOrder, $active, 500);
        app(ShippingMethodRepository::class)->save($method);

        return $method;
    }

    // =====================================================================================================
    // Reorder
    // =====================================================================================================

    public function test_a_move_swaps_with_the_neighbour_and_rewrites_a_dense_order_even_when_the_stored_orders_are_tied_or_gapped(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->methodAt($zone, 'A', 0);
        $b = $this->methodAt($zone, 'B', 0);   // tied with A
        $this->methodAt($zone, 'C', 7);        // gapped
        $d = $this->methodAt($zone, 'D', 7);   // tied with C

        $this->assertSame(['A', 'B', 'C', 'D'], $this->methodNames($zone));

        $this->assertTrue($this->reorderer()->moveDown((string) $b->id()));
        $this->assertSame(['A', 'C', 'B', 'D'], $this->methodNames($zone));
        $this->assertSame([0, 1, 2, 3], $this->methodSortOrders($zone), 'dense 0..n-1');

        $this->assertTrue($this->reorderer()->moveUp((string) $d->id()));
        $this->assertSame(['A', 'C', 'D', 'B'], $this->methodNames($zone));
        $this->assertSame([0, 1, 2, 3], $this->methodSortOrders($zone));
    }

    public function test_a_move_never_touches_another_zones_methods(): void
    {
        $one = (string) $this->zone('One', 0)->id();
        $two = (string) $this->zone('Two', 1)->id();
        $this->methodAt($one, 'A', 0);
        $b = $this->methodAt($one, 'B', 1);
        $this->methodAt($two, 'X', 4);
        $this->methodAt($two, 'Y', 9);

        $this->reorderer()->moveUp((string) $b->id());

        $this->assertSame(['B', 'A'], $this->methodNames($one));
        $this->assertSame([4, 9], $this->methodSortOrders($two), 'the other zone is untouched, gaps and all');
    }

    public function test_moving_the_first_method_up_or_the_last_down_is_a_no_op_that_writes_nothing(): void
    {
        $this->activityLogOn();
        $zone = (string) $this->zone('Z', 0)->id();
        $first = $this->methodAt($zone, 'First', 0);
        $last = $this->methodAt($zone, 'Last', 5);   // gapped on purpose: a no-op must not even tidy the numbers
        $this->spyOnMethodHooks();
        $before = DB::table('shipping_methods')->orderBy('id')->get()->all();

        $this->assertFalse($this->reorderer()->moveUp((string) $first->id()));
        $this->assertFalse($this->reorderer()->moveDown((string) $last->id()));

        $this->assertEquals($before, DB::table('shipping_methods')->orderBy('id')->get()->all());
        $this->assertSame([], $this->methodAuditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_a_move_writes_one_audit_entry_naming_the_two_methods_and_fires_one_hook_after_the_commit(): void
    {
        $this->activityLogOn();
        $zone = (string) $this->zone('Z', 0)->id();
        $this->methodAt($zone, 'Top', 0);
        $bottom = $this->methodAt($zone, 'Bottom', 1);
        $baseline = $this->spyOnMethodHooks();

        $this->reorderer()->moveUp((string) $bottom->id());

        $rows = $this->methodAuditRows();
        $this->assertCount(1, $rows);
        $this->assertSame('order', $rows[0]->field);
        $this->assertSame((string) $bottom->id(), (string) $rows[0]->entity_id);

        $before = json_decode($rows[0]->old_value, true)['methods'];
        $after = json_decode($rows[0]->new_value, true)['methods'];

        $this->assertSame(['Bottom', 'Top'], array_column($before, 'name'));
        $this->assertSame([1, 0], array_column($before, 'position'));
        $this->assertSame([0, 1], array_column($after, 'position'));

        $this->assertCount(1, $this->hookCalls);
        $this->assertSame('shipping.method.reordered', $this->hookCalls[0][0]);
        $this->assertSame('up', $this->hookCalls[0][1][0]['direction']);
        $this->assertSame($zone, $this->hookCalls[0][1][0]['zone_id']);
        $this->assertSame($baseline, $this->hookCalls[0][2], 'fired outside the transaction');
    }

    public function test_moving_an_unknown_method_is_a_translated_not_found(): void
    {
        $this->expectException(ShippingMethodNotFoundException::class);

        $this->reorderer()->moveUp('999999');
    }

    // =====================================================================================================
    // Copy
    // =====================================================================================================

    public function test_a_copy_has_every_field_class_amounts_included_and_keeps_the_active_state(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->shippingClass('discount', 'Discount');
        $source = (string) $this->zone('Source', 0)->id();
        $t1 = (string) $this->zone('Target one', 1)->id();
        $t2 = (string) $this->zone('Target two', 2)->id();
        $original = ShippingMethod::create($source, 'Econt', ShippingMethodKind::PER_CLASS, 0, false, 500, ['heavy' => 2500, 'discount' => -300], 9000, null, true, ShippingClassMode::ADJUST);
        app(ShippingMethodRepository::class)->save($original);

        $result = $this->copier()->copyToZones((string) $original->id(), [$t1, $t2]);

        $this->assertCount(2, $result->created);
        $this->assertSame([], $result->zonesWithSameNameAndKind);

        foreach ([$t1, $t2] as $zone) {
            [$copy] = app(ShippingMethodRepository::class)->forZone($zone);

            $this->assertNotSame($original->id(), $copy->id(), 'an independent row');
            $this->assertSame('Econt', $copy->name(), 'the name is unchanged');
            $this->assertSame(ShippingMethodKind::PER_CLASS, $copy->kind());
            $this->assertFalse($copy->isActive(), 'the ACTIVE state is preserved');
            $this->assertSame(500, $copy->amountMinor());
            $this->assertSame(9000, $copy->freeAboveMinor());
            $this->assertTrue($copy->requiresPickupPoint());
            $this->assertSame(ShippingClassMode::ADJUST, $copy->classMode());
            $this->assertSame(['discount' => -300, 'heavy' => 2500], $copy->classRates());
            $this->assertSame($zone, $copy->zoneId());
        }

        $this->assertSame(['Econt'], $this->methodNames($source), 'the source is untouched');
    }

    public function test_a_copy_is_appended_at_the_end_of_each_target_zone(): void
    {
        $source = (string) $this->zone('Source', 0)->id();
        $target = (string) $this->zone('Target', 1)->id();
        $this->methodAt($target, 'Existing 1', 0);
        $this->methodAt($target, 'Existing 2', 6);
        $original = $this->methodAt($source, 'Copied', 0);

        $this->copier()->copyToZones((string) $original->id(), [$target]);

        $this->assertSame(['Existing 1', 'Existing 2', 'Copied'], $this->methodNames($target));
        $this->assertSame([0, 6, 7], $this->methodSortOrders($target));
    }

    public function test_one_audit_entry_per_created_method_and_one_hook_per_method_after_the_commit(): void
    {
        $this->activityLogOn();
        $source = (string) $this->zone('Source', 0)->id();
        $t1 = (string) $this->zone('T1', 1)->id();
        $t2 = (string) $this->zone('T2', 2)->id();
        $t3 = (string) $this->zone('T3', 3)->id();
        $original = $this->methodAt($source, 'Econt', 0);
        $baseline = $this->spyOnMethodHooks();

        $result = $this->copier()->copyToZones((string) $original->id(), [$t1, $t2, $t3]);

        $rows = $this->methodAuditRows();
        $this->assertCount(3, $rows, 'one entry PER created method');

        foreach ($rows as $i => $row) {
            $this->assertSame('created', $row->action);
            $this->assertSame('copied_from', $row->field);
            $this->assertSame((string) $original->id(), $row->new_value);
            $this->assertSame((string) $result->created[$i]->id(), (string) $row->entity_id);
        }

        $this->assertCount(3, $this->hookCalls);

        foreach ($this->hookCalls as [$name, $arguments, $level]) {
            $this->assertSame('shipping.method.created', $name);
            $this->assertSame((string) $original->id(), $arguments[1], 'the hook names the source');
            $this->assertSame($baseline, $level, 'after the commit, never inside it');
        }
    }

    public function test_a_zone_that_already_has_the_same_name_and_kind_is_copied_to_anyway_and_reported(): void
    {
        $source = (string) $this->zone('Source', 0)->id();
        $has = (string) $this->zone('Has one', 1)->id();
        $without = (string) $this->zone('Has none', 2)->id();
        $this->methodAt($has, 'Econt', 0);
        $original = $this->methodAt($source, 'Econt', 0);

        $result = $this->copier()->copyToZones((string) $original->id(), [$has, $without]);

        $this->assertCount(2, $result->created);
        $this->assertSame([$has], $result->zonesWithSameNameAndKind);
        $this->assertSame(['Econt', 'Econt'], $this->methodNames($has), 'a one-name-per-zone rule is not enforced');
    }

    /** @return array<string, list<string>> */
    private function copyRefusal(string $methodId, array $zones): array
    {
        try {
            $this->copier()->copyToZones($methodId, $zones);
        } catch (ShippingMethodInvalidException $exception) {
            return $exception->errors;
        }

        $this->fail('expected a ShippingMethodInvalidException.');
    }

    public function test_the_copy_refusals_are_translated_and_write_nothing(): void
    {
        $this->activityLogOn();
        $source = (string) $this->zone('Source', 0)->id();
        $other = (string) $this->zone('Other', 1)->id();
        $original = $this->methodAt($source, 'Econt', 0);
        $this->spyOnMethodHooks();

        foreach ([
            'empty selection' => [],
            'the source zone itself' => [$source],
            'the source zone among others' => [$other, $source],
            'an unknown zone' => [$other, '999999'],
            'more than 50 zones' => array_map(fn (int $i): string => (string) (1000 + $i), range(1, 51)),
        ] as $label => $zones) {
            $errors = $this->copyRefusal((string) $original->id(), $zones);

            $this->assertArrayHasKey('zones', $errors, $label);
            $this->assertStringNotContainsString('shipping.methods', $errors['zones'][0], $label);
        }

        $this->assertSame(1, DB::table('shipping_methods')->count());
        $this->assertSame([], $this->methodAuditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_exactly_50_zones_are_accepted(): void
    {
        $source = (string) $this->zone('Source', 0)->id();
        $original = $this->methodAt($source, 'Econt', 0);
        $targets = [];

        for ($i = 1; $i <= 50; $i++) {
            $targets[] = (string) $this->zone("Zone {$i}", $i)->id();
        }

        $result = $this->copier()->copyToZones((string) $original->id(), $targets);

        $this->assertCount(50, $result->created);
    }

    public function test_copying_an_unknown_method_is_a_translated_not_found(): void
    {
        $target = (string) $this->zone('Target', 1)->id();

        $this->expectException(ShippingMethodNotFoundException::class);

        $this->copier()->copyToZones('999999', [$target]);
    }

    public function test_the_copy_is_atomic_if_one_target_fails_none_is_created_and_no_audit_or_hook_remains(): void
    {
        $this->activityLogOn();
        $source = (string) $this->zone('Source', 0)->id();
        $t1 = (string) $this->zone('T1', 1)->id();
        $t2 = (string) $this->zone('T2', 2)->id();
        $original = $this->methodAt($source, 'Econt', 0);
        $this->spyOnMethodHooks();
        $auditBefore = count($this->methodAuditRows());

        // The repository fails on the SECOND save, after the first copy was written.
        $real = app(ShippingMethodRepository::class);
        $saves = 0;
        $this->app->bind(ShippingMethodRepository::class, fn () => new class($real, $saves) implements ShippingMethodRepository {
            public function __construct(private ShippingMethodRepository $inner, private int &$saves)
            {
            }

            public function save(ShippingMethod $method): void
            {
                if ($method->id() === null && ++$this->saves === 2) {
                    throw new \Illuminate\Database\QueryException('mysql', 'insert', [], new \RuntimeException('the second copy failed'));
                }

                $this->inner->save($method);
            }

            public function findById(string $id): ?ShippingMethod
            {
                return $this->inner->findById($id);
            }

            public function delete(string $id): void
            {
                $this->inner->delete($id);
            }

            public function forZone(string $zoneId, bool $activeOnly = false): array
            {
                return $this->inner->forZone($zoneId, $activeOnly);
            }

            public function forZones(array $zoneIds, bool $activeOnly = false): array
            {
                return $this->inner->forZones($zoneIds, $activeOnly);
            }
        });

        try {
            app(ShippingMethodCopier::class)->copyToZones((string) $original->id(), [$t1, $t2]);
            $this->fail('the second target fails.');
        } catch (\Illuminate\Database\QueryException) {
            // expected
        }

        $this->assertSame(1, DB::table('shipping_methods')->count(), 'the first copy was rolled back');
        $this->assertCount($auditBefore, $this->methodAuditRows());
        $this->assertSame([], $this->hookCalls);
    }
}
