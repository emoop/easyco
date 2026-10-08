<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingZoneInUseException;
use App\Services\Exceptions\ShippingZoneInvalidException;
use App\Services\Exceptions\ShippingZoneNotFoundException;
use App\Services\ShippingTester;
use App\Services\ShippingZoneReorderer;
use App\Services\ShippingZoneWriter;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5c (shipping-domain-design.md §12.3.2, §12.6): the zone WRITERS — create / update / delete and the
 * reorderer — validate through the domain, write exactly one audit entry, fire exactly one hook after the commit,
 * and refuse in translated messages with nothing written.
 */
class ShippingZoneWriterTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function writer(): ShippingZoneWriter
    {
        return app(ShippingZoneWriter::class);
    }

    private function reorderer(): ShippingZoneReorderer
    {
        return app(ShippingZoneReorderer::class);
    }

    private function zoneCount(): int
    {
        return DB::table('shipping_zones')->count();
    }

    /** @return array<string, list<string>> */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ShippingZoneInvalidException $exception) {
            return $exception->errors;
        }

        $this->fail('expected a ShippingZoneInvalidException.');
    }

    // =====================================================================================================
    // Create
    // =====================================================================================================

    public function test_a_zone_is_stored_as_entered_with_its_settlements_and_a_normalised_postcode_list(): void
    {
        $zone = $this->writer()->create("  Sofia region  ", ['bg'], ['гр. София', '  Пловдив '], ['sw1a 1aa', ' 1000 ']);

        $stored = app(ShippingZoneRepository::class)->findById((string) $zone->id());

        $this->assertSame('Sofia region', $stored->name(), 'trimmed');
        $this->assertSame(['BG'], $stored->countryCodes(), 'uppercase ISO-2');
        $this->assertSame(['гр. София', 'Пловдив'], $stored->settlementNames(), 'as entered (trimmed), not normalised');
        $this->assertSame(['SW1A1AA', '1000'], $stored->postcodes(), 'normalised by the matcher\'s own normaliser');
    }

    public function test_empty_lists_are_stored_as_null(): void
    {
        $zone = $this->writer()->create('Everywhere', ['BG', 'RO'], [], []);

        $stored = app(ShippingZoneRepository::class)->findById((string) $zone->id());

        $this->assertNull($stored->settlementNames());
        $this->assertNull($stored->postcodes());
        $this->assertNull(DB::table('shipping_zones')->where('id', $zone->id())->value('settlement_names'));
        $this->assertNull(DB::table('shipping_zones')->where('id', $zone->id())->value('postcodes'));
    }

    public function test_a_new_zone_is_appended_at_the_end_of_the_order_even_when_the_stored_orders_have_gaps(): void
    {
        $this->assertSame(0, $this->writer()->create('First', ['BG'], [], [])->sortOrder(), 'the first zone starts the order');

        $this->zone('Gapped', 6);

        $last = $this->writer()->create('Last', ['BG'], [], []);

        $this->assertSame(7, $last->sortOrder(), 'highest + 1');
        $this->assertSame(['First', 'Gapped', 'Last'], $this->zoneNames());
    }

    public function test_invalid_input_is_a_translated_field_error_and_writes_nothing(): void
    {
        $this->activityLogOn();
        $this->spyOnZoneHooks();

        $cases = [
            'name_empty' => [fn () => $this->writer()->create('   ', ['BG'], [], []), 'name'],
            'name_256' => [fn () => $this->writer()->create(str_repeat('a', 256), ['BG'], [], []), 'name'],
            'name_control' => [fn () => $this->writer()->create("Bad\nname", ['BG'], [], []), 'name'],
            'name_bidi' => [fn () => $this->writer()->create("Evil\u{202E}name", ['BG'], [], []), 'name'],
            'name_nul' => [fn () => $this->writer()->create("A\0B", ['BG'], [], []), 'name'],
            'no_country' => [fn () => $this->writer()->create('Z', [], [], []), 'country_codes'],
            'unknown_country' => [fn () => $this->writer()->create('Z', ['ZZ'], [], []), 'country_codes'],
            'country_shape' => [fn () => $this->writer()->create('Z', ['BGR'], [], []), 'country_codes'],
            'duplicate_country' => [fn () => $this->writer()->create('Z', ['BG', 'bg'], [], []), 'country_codes'],
            'settlement_empty' => [fn () => $this->writer()->create('Z', ['BG'], ['  '], []), 'settlement_names'],
            'settlement_duplicate' => [fn () => $this->writer()->create('Z', ['BG'], ['София', ' София '], []), 'settlement_names'],
            'settlement_256' => [fn () => $this->writer()->create('Z', ['BG'], [str_repeat('я', 256)], []), 'settlement_names'],
            'settlement_control' => [fn () => $this->writer()->create('Z', ['BG'], ["a\tb"], []), 'settlement_names'],
            'settlement_bidi' => [fn () => $this->writer()->create('Z', ['BG'], ["a\u{202E}b"], []), 'settlement_names'],
            'settlement_501' => [fn () => $this->writer()->create('Z', ['BG'], array_map(fn (int $i): string => "Town {$i}", range(1, 501)), []), 'settlement_names'],
            'postcode_unnormalisable' => [fn () => $this->writer()->create('Z', ['BG'], [], ['!!']), 'postcodes'],
            'postcode_too_short' => [fn () => $this->writer()->create('Z', ['BG'], [], ['1']), 'postcodes'],
            'postcode_normalises_too_long' => [fn () => $this->writer()->create('Z', ['BG'], [], ['1234 5678 9012 3']), 'postcodes'],
            'postcode_21_chars' => [fn () => $this->writer()->create('Z', ['BG'], [], [str_repeat('1', 21)]), 'postcodes'],
            'postcode_duplicate_after_normalisation' => [fn () => $this->writer()->create('Z', ['BG'], [], ['ab 12', 'AB12']), 'postcodes'],
            'postcode_control' => [fn () => $this->writer()->create('Z', ['BG'], [], ["12\n34"]), 'postcodes'],
            'postcode_501' => [fn () => $this->writer()->create('Z', ['BG'], [], array_map(fn (int $i): string => (string) (1000 + $i), range(1, 501))), 'postcodes'],
            'postcode_not_a_string' => [fn () => $this->writer()->create('Z', ['BG'], [], [123]), 'postcodes'],
        ];

        foreach ($cases as $label => [$call, $field]) {
            $errors = $this->refusal($call);

            $this->assertArrayHasKey($field, $errors, "{$label}: the error is on {$field}");
            $this->assertNotSame('', $errors[$field][0], $label);
            $this->assertStringNotContainsString('ShippingZone ', $errors[$field][0], "{$label}: the domain's raw English message is never shown");
            $this->assertStringNotContainsString('shipping.zones', $errors[$field][0], "{$label}: a translated sentence, not a key");
            $this->assertStringNotContainsString('SQLSTATE', $errors[$field][0], $label);
        }

        $this->assertSame(0, $this->zoneCount(), 'nothing written');
        $this->assertSame([], $this->auditRows(), 'no audit entry');
        $this->assertSame([], $this->hookCalls, 'no hook');
    }

    public function test_exactly_500_entries_are_accepted(): void
    {
        $zone = $this->writer()->create(
            'Many',
            ['BG'],
            array_map(fn (int $i): string => "Town {$i}", range(1, 500)),
            array_map(fn (int $i): string => (string) (1000 + $i), range(1, 500)),
        );

        $this->assertCount(500, $zone->settlementNames());
        $this->assertCount(500, $zone->postcodes());
    }

    public function test_the_refusals_are_in_bulgarian_in_bg(): void
    {
        App::setLocale('bg');

        $errors = $this->refusal(fn () => $this->writer()->create('', [], [], ['!!']));

        $this->assertSame('Въведете име на зоната.', $errors['name'][0]);
        $this->assertSame('Изберете поне една държава.', $errors['country_codes'][0]);
        $this->assertStringContainsString('не е валиден', $errors['postcodes'][0]);
    }

    // =====================================================================================================
    // Update
    // =====================================================================================================

    public function test_an_update_round_trips_every_field_and_never_changes_the_order(): void
    {
        $this->zone('First', 0);
        $zone = $this->zone('Second', 3, ['BG'], ['София'], ['1000']);
        $this->zone('Third', 9);

        $this->writer()->update((string) $zone->id(), 'Renamed', ['RO', 'GR'], ['Букурещ', 'Атина'], ['aa 11', 'BB22']);

        $stored = app(ShippingZoneRepository::class)->findById((string) $zone->id());

        $this->assertSame('Renamed', $stored->name());
        $this->assertSame(['RO', 'GR'], $stored->countryCodes());
        $this->assertSame(['Букурещ', 'Атина'], $stored->settlementNames());
        $this->assertSame(['AA11', 'BB22'], $stored->postcodes());
        $this->assertSame(3, $stored->sortOrder(), 'the order is NOT the update\'s to change');
        $this->assertSame([0, 3, 9], $this->sortOrders());
        $this->assertSame(['First', 'Renamed', 'Third'], $this->zoneNames());
    }

    public function test_an_update_can_empty_the_narrowing_lists(): void
    {
        $zone = $this->zone('Narrow', 0, ['BG'], ['София'], ['1000']);

        $this->writer()->update((string) $zone->id(), 'Narrow', ['BG'], [], []);

        $stored = app(ShippingZoneRepository::class)->findById((string) $zone->id());
        $this->assertNull($stored->settlementNames());
        $this->assertNull($stored->postcodes());
    }

    public function test_an_invalid_update_is_a_field_error_and_changes_nothing(): void
    {
        $this->activityLogOn();
        $zone = $this->zone('Keep', 2, ['BG'], ['София'], ['1000']);
        $before = DB::table('shipping_zones')->where('id', $zone->id())->first();
        $this->spyOnZoneHooks();

        $errors = $this->refusal(fn () => $this->writer()->update((string) $zone->id(), '', ['BG'], ['София'], ['1000']));

        $this->assertArrayHasKey('name', $errors);
        $this->assertEquals($before, DB::table('shipping_zones')->where('id', $zone->id())->first());
        $this->assertSame([], $this->auditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_an_update_that_changes_nothing_writes_no_audit_entry_and_fires_no_hook(): void
    {
        $this->activityLogOn();
        $zone = $this->zone('Same', 0, ['BG'], ['София'], ['1000']);
        $this->spyOnZoneHooks();

        $this->writer()->update((string) $zone->id(), 'Same', ['BG'], ['София'], ['1000']);

        $this->assertSame([], $this->auditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_updating_or_deleting_an_unknown_zone_is_a_translated_not_found(): void
    {
        foreach ([
            fn () => $this->writer()->update('999999', 'X', ['BG'], [], []),
            fn () => $this->writer()->delete('999999'),
            fn () => $this->reorderer()->moveUp('999999'),
        ] as $call) {
            try {
                $call();
                $this->fail('an unknown zone must be refused.');
            } catch (ShippingZoneNotFoundException $exception) {
                $this->assertStringNotContainsString('shipping.zones', $exception->getMessage());
            }
        }
    }

    // =====================================================================================================
    // Audit and hooks
    // =====================================================================================================

    public function test_every_write_writes_exactly_one_audit_entry_and_fires_its_hook_after_commit(): void
    {
        $this->activityLogOn();
        $baseline = $this->spyOnZoneHooks();
        $rowSeenByTheListener = [];

        foreach (['shipping.zone.created', 'shipping.zone.updated', 'shipping.zone.reordered'] as $name) {
            Hook::action($name, function (...$arguments) use (&$rowSeenByTheListener, $name): void {
                // After the commit, the changed rows are visible to a plain read.
                $rowSeenByTheListener[$name] = DB::table('shipping_zones')->count();
            });
        }

        // create
        $a = $this->writer()->create('Alpha', ['BG'], ['София'], ['1000']);
        $this->assertCount(1, $this->auditRows());
        $this->assertSame(1, $rowSeenByTheListener['shipping.zone.created']);

        // update
        $this->writer()->update((string) $a->id(), 'Alpha 2', ['BG'], ['София'], ['1000']);
        $this->assertCount(2, $this->auditRows());

        // reorder (needs a second zone; its creation is its own write)
        $b = $this->writer()->create('Beta', ['BG'], [], []);
        $this->assertCount(3, $this->auditRows());
        $this->assertTrue($this->reorderer()->moveUp((string) $b->id()));
        $this->assertCount(4, $this->auditRows());
        $this->assertSame(2, $rowSeenByTheListener['shipping.zone.reordered']);

        // delete
        $this->writer()->delete((string) $b->id());
        $this->assertCount(5, $this->auditRows());

        $rows = $this->auditRows();
        $this->assertSame(['created', 'updated', 'created', 'updated', 'deleted'], array_map(fn ($row) => $row->action, $rows));
        $this->assertSame([null, 'zone', null, 'order', null], array_map(fn ($row) => $row->field, $rows));
        $this->assertSame(['shipping_zone'], array_values(array_unique(array_map(fn ($row) => $row->entity_type, $rows))));

        // exactly one hook per write, in order, each AFTER the transaction (the depth is the baseline's again)
        $this->assertSame(
            ['shipping.zone.created', 'shipping.zone.updated', 'shipping.zone.created', 'shipping.zone.reordered', 'shipping.zone.deleted'],
            array_map(fn (array $call): string => $call[0], $this->hookCalls),
        );

        foreach ($this->hookCalls as [$name, , $level]) {
            $this->assertSame($baseline, $level, "{$name} must fire outside the writer's transaction");
        }
    }

    public function test_the_update_audit_entry_carries_compact_json_snapshots_before_and_after(): void
    {
        $this->activityLogOn();
        $zone = $this->zone('Old name', 4, ['BG'], null, null);

        $this->writer()->update((string) $zone->id(), 'Нова зона', ['BG', 'RO'], ['гр. София'], ['sw1a 1aa']);

        [$row] = $this->auditRows();
        $before = json_decode($row->old_value, true);
        $after = json_decode($row->new_value, true);

        $this->assertSame('zone', $row->field);
        $this->assertSame((string) $zone->id(), (string) $row->entity_id);
        $this->assertSame('Old name', $before['name']);
        $this->assertSame('Нова зона', $after['name']);
        $this->assertSame(['BG', 'RO'], $after['countries']);
        $this->assertSame(['гр. София'], $after['settlements']);
        $this->assertSame(['SW1A1AA'], $after['postcodes']);
        $this->assertSame(4, $after['sort_order']);
        $this->assertStringContainsString('Нова зона', $row->new_value, 'Cyrillic is stored as itself, not as \\u escapes');
    }

    public function test_the_updated_hook_carries_the_entity_and_the_before_snapshot_and_the_deleted_hook_the_snapshot(): void
    {
        $this->spyOnZoneHooks();
        $zone = $this->zone('Before', 1, ['BG'], ['София'], null);

        $this->writer()->update((string) $zone->id(), 'After', ['BG'], ['София'], []);
        $this->writer()->delete((string) $zone->id());

        [$updated, $deleted] = $this->hookCalls;

        $this->assertSame('After', $updated[1][0]->name());
        $this->assertSame('Before', $updated[1][1]['name']);
        $this->assertSame(['София'], $updated[1][1]['settlements']);
        $this->assertSame('shipping.zone.deleted', $deleted[0]);
        $this->assertSame('After', $deleted[1][0]['name']);
        $this->assertSame((string) $zone->id(), $deleted[1][0]['id']);
    }

    public function test_a_failed_write_fires_no_hook_and_writes_no_audit_entry(): void
    {
        $this->activityLogOn();
        $zone = $this->zone('Busy', 0);
        $this->method((string) $zone->id());
        $this->spyOnZoneHooks();

        try {
            $this->writer()->delete((string) $zone->id());
            $this->fail('a zone with a method cannot be deleted.');
        } catch (ShippingZoneInUseException) {
            // expected
        }

        try {
            $this->writer()->create('', ['BG'], [], []);
            $this->fail('an invalid zone');
        } catch (ShippingZoneInvalidException) {
            // expected
        }

        $this->assertSame([], $this->hookCalls);
        $this->assertSame([], $this->auditRows());
        $this->assertSame(1, $this->zoneCount());
    }

    public function test_a_listener_that_throws_cannot_undo_the_committed_write(): void
    {
        Hook::action('shipping.zone.created', function (): void {
            throw new \RuntimeException('a listener failed');
        });

        try {
            $this->writer()->create('Durable', ['BG'], [], []);
            $this->fail('the listener\'s error surfaces');
        } catch (\RuntimeException $exception) {
            $this->assertSame('a listener failed', $exception->getMessage());
        }

        $this->assertSame(1, $this->zoneCount(), 'the write is already durable — the hook fires after the commit');
    }

    // =====================================================================================================
    // Delete
    // =====================================================================================================

    public function test_a_zone_with_methods_is_refused_with_the_count_and_nothing_is_written(): void
    {
        $this->activityLogOn();
        $zone = $this->zone('Has methods', 0);
        $this->method((string) $zone->id(), 'One');
        $this->method((string) $zone->id(), 'Two');
        $this->spyOnZoneHooks();

        try {
            $this->writer()->delete((string) $zone->id());
            $this->fail('refused');
        } catch (ShippingZoneInUseException $exception) {
            $this->assertSame(2, $exception->methodCount);
            $this->assertStringContainsString('2', $exception->getMessage());
            $this->assertStringContainsString('Has methods', $exception->getMessage());
            $this->assertStringContainsString('methods', $exception->getMessage());
        }

        $this->assertSame(1, $this->zoneCount());
        $this->assertSame(2, DB::table('shipping_methods')->count());
        $this->assertSame([], $this->auditRows());
        $this->assertSame([], $this->hookCalls);

        App::setLocale('bg');
        try {
            $this->writer()->delete((string) $zone->id());
        } catch (ShippingZoneInUseException $exception) {
            $this->assertStringContainsString('метода', $exception->getMessage());
        }
    }

    public function test_one_method_uses_the_singular_sentence(): void
    {
        $zone = $this->zone('Single', 0);
        $this->method((string) $zone->id());

        try {
            $this->writer()->delete((string) $zone->id());
        } catch (ShippingZoneInUseException $exception) {
            $this->assertStringContainsString('1 shipping method.', $exception->getMessage());
        }
    }

    public function test_an_empty_zone_is_deleted_with_one_audit_snapshot_and_one_hook(): void
    {
        // A delete is written even while the activity log is off (the one deliberate exception of the logger).
        $zone = $this->zone('Gone', 0, ['BG'], ['София'], ['1000']);
        $this->spyOnZoneHooks();

        $this->writer()->delete((string) $zone->id());

        $this->assertSame(0, $this->zoneCount());
        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $this->assertSame('deleted', $rows[0]->action);
        $snapshot = json_decode($rows[0]->old_value, true);
        $this->assertSame('Gone', $snapshot['name']);
        $this->assertSame(['София'], $snapshot['settlements']);
        $this->assertSame(['1000'], $snapshot['postcodes']);
        $this->assertNull($rows[0]->new_value);
        $this->assertCount(1, $this->hookCalls);
        $this->assertSame('shipping.zone.deleted', $this->hookCalls[0][0]);
    }

    public function test_the_database_foreign_key_is_the_backstop_only(): void
    {
        $zone = $this->zone('Guarded', 0);
        $this->method((string) $zone->id());

        try {
            app(ShippingZoneRepository::class)->delete((string) $zone->id());
            $this->fail('the restrict foreign key must refuse it.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }

        $this->assertSame(1, $this->zoneCount());
    }

    public function test_the_repository_delete_of_an_unknown_id_is_a_no_op(): void
    {
        $this->zone('Stays', 0);

        app(ShippingZoneRepository::class)->delete('424242');

        $this->assertSame(1, $this->zoneCount());
    }

    // =====================================================================================================
    // Reorder
    // =====================================================================================================

    public function test_a_move_swaps_with_the_neighbour_and_rewrites_a_dense_order_even_when_the_stored_orders_are_tied_or_gapped(): void
    {
        $this->zone('A', 0);
        $b = $this->zone('B', 0);   // tied with A (ties break by id)
        $this->zone('C', 7);        // gapped
        $d = $this->zone('D', 7);   // tied with C

        $this->assertSame(['A', 'B', 'C', 'D'], $this->zoneNames());

        $this->assertTrue($this->reorderer()->moveDown((string) $b->id()));

        $this->assertSame(['A', 'C', 'B', 'D'], $this->zoneNames());
        $this->assertSame([0, 1, 2, 3], $this->sortOrders(), 'dense 0..n-1');

        $this->assertTrue($this->reorderer()->moveUp((string) $d->id()));

        $this->assertSame(['A', 'C', 'D', 'B'], $this->zoneNames());
        $this->assertSame([0, 1, 2, 3], $this->sortOrders());
    }

    public function test_moving_the_first_zone_up_or_the_last_down_is_a_no_op_that_writes_nothing(): void
    {
        $this->activityLogOn();
        $first = $this->zone('First', 0);
        $last = $this->zone('Last', 5);   // gapped on purpose: a no-op must not even tidy the numbers
        $this->spyOnZoneHooks();
        $before = DB::table('shipping_zones')->orderBy('id')->get()->all();

        $this->assertFalse($this->reorderer()->moveUp((string) $first->id()));
        $this->assertFalse($this->reorderer()->moveDown((string) $last->id()));

        $this->assertEquals($before, DB::table('shipping_zones')->orderBy('id')->get()->all());
        $this->assertSame([], $this->auditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_a_single_zone_cannot_move(): void
    {
        $only = $this->zone('Only', 3);

        $this->assertFalse($this->reorderer()->moveUp((string) $only->id()));
        $this->assertFalse($this->reorderer()->moveDown((string) $only->id()));
        $this->assertSame([3], $this->sortOrders());
    }

    public function test_a_move_writes_one_audit_entry_naming_the_two_zones_before_and_after(): void
    {
        $this->activityLogOn();
        $this->zone('Top', 0);
        $bottom = $this->zone('Bottom', 1);
        $this->spyOnZoneHooks();

        $this->reorderer()->moveUp((string) $bottom->id());

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $this->assertSame('order', $rows[0]->field);
        $this->assertSame((string) $bottom->id(), (string) $rows[0]->entity_id);

        $before = json_decode($rows[0]->old_value, true)['zones'];
        $after = json_decode($rows[0]->new_value, true)['zones'];

        $this->assertSame(['Bottom', 'Top'], array_column($before, 'name'));
        $this->assertSame([1, 0], array_column($before, 'position'));
        $this->assertSame([0, 1], array_column($after, 'position'));

        $this->assertCount(1, $this->hookCalls);
        $this->assertSame('shipping.zone.reordered', $this->hookCalls[0][0]);
        $this->assertSame('up', $this->hookCalls[0][1][0]['direction']);
        $this->assertCount(2, $this->hookCalls[0][1][0]['zones']);
    }

    public function test_reordering_the_zones_rewrites_the_match_order(): void
    {
        // A broad Bulgaria zone first, a Sofia-narrowed zone below it: Sofia is swallowed by the broad one.
        $this->zone('Bulgaria', 0, ['BG']);
        $sofia = $this->zone('Sofia', 1, ['BG'], ['София']);
        $tester = fn (): ?string => app(ShippingTester::class)->run('BG', 'София', null, false, 10000, [new \EasyCo\Shipping\Rating\RateLine(null, 1)])->zoneName;

        $this->assertSame('Bulgaria', $tester(), 'the broad zone is first, so it wins');

        $this->reorderer()->moveUp((string) $sofia->id());
        $this->assertSame('Sofia', $tester(), 'moved above the broad zone it matches first, in the real matcher');
        $this->assertSame('Bulgaria', app(ShippingTester::class)->run('BG', 'Варна', null, false, 10000, [new \EasyCo\Shipping\Rating\RateLine(null, 1)])->zoneName, 'another town still falls to the broad zone');

        $this->reorderer()->moveDown((string) $sofia->id());
        $this->assertSame('Bulgaria', $tester(), 'moved below it again, it no longer matches');
    }
}
