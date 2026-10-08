<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingMethodInUseException;
use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\Exceptions\ShippingMethodNotFoundException;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodWriter;
use EasyCo\Extensibility\Hook;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5d (shipping-domain-design.md §12.3.3, §12.6): the method WRITER — create / update / delete / (de)activate —
 * validates per kind through the domain, writes exactly one audit entry, fires exactly one hook after the commit, and
 * refuses in translated messages with nothing written. The store currency is the default one (EUR in the tests).
 */
class ShippingMethodWriterTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private string $zoneId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneId = (string) $this->zone('Bulgaria', 0)->id();
    }

    private function writer(): ShippingMethodWriter
    {
        return app(ShippingMethodWriter::class);
    }

    private function eur(int $minor): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    /** @param array<string, mixed> $overrides */
    private function input(array $overrides = []): ShippingMethodInput
    {
        $values = [
            'name' => 'Courier',
            'kind' => 'flat',
            'active' => true,
            'price' => $this->eur(500),
            'freeAbove' => null,
            'classMode' => 'replace',
            'classRates' => [],
            'requiresPickupPoint' => false,
            'carrierCode' => null,
        ] + [];

        return new ShippingMethodInput(...array_merge($values, $overrides));
    }

    /** @return array<string, list<string>> */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ShippingMethodInvalidException $exception) {
            return $exception->errors;
        }

        $this->fail('expected a ShippingMethodInvalidException.');
    }

    private function stored(string $id): \EasyCo\Shipping\ShippingMethod
    {
        return app(ShippingMethodRepository::class)->findById($id);
    }

    private function methodCount(): int
    {
        return DB::table('shipping_methods')->count();
    }

    // =====================================================================================================
    // Create, per kind
    // =====================================================================================================

    public function test_a_flat_method_is_stored_with_its_price_threshold_and_pickup_flag(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input(['name' => '  Econt  ', 'freeAbove' => $this->eur(10000), 'requiresPickupPoint' => true]));

        $stored = $this->stored((string) $method->id());

        $this->assertSame('Econt', $stored->name());
        $this->assertSame(ShippingMethodKind::FLAT, $stored->kind());
        $this->assertSame(500, $stored->amountMinor());
        $this->assertSame(10000, $stored->freeAboveMinor());
        $this->assertTrue($stored->requiresPickupPoint());
        $this->assertTrue($stored->isActive());
        $this->assertSame(ShippingClassMode::REPLACE, $stored->classMode());
        $this->assertSame($this->zoneId, $stored->zoneId());
    }

    public function test_a_free_method_has_no_price_and_ignores_what_the_form_left_behind(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input([
            'name' => 'Free', 'kind' => 'free', 'price' => $this->eur(700), 'freeAbove' => $this->eur(5000),
            'classMode' => 'adjust', 'carrierCode' => 'econt',
        ]));

        $stored = $this->stored((string) $method->id());

        $this->assertNull($stored->amountMinor(), 'the stale price is cleared');
        $this->assertNull($stored->freeAboveMinor(), 'a threshold on something free is cleared, not refused');
        $this->assertNull($stored->carrierCode());
        $this->assertSame(ShippingClassMode::REPLACE, $stored->classMode());
        $this->assertSame([], $stored->classRates());
    }

    public function test_a_per_class_method_in_replace_mode_stores_its_class_amounts(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->shippingClass('light', 'Light');

        $method = $this->writer()->create($this->zoneId, $this->input([
            'name' => 'By class', 'kind' => 'per_class',
            'classRates' => [['class' => 'heavy', 'amount' => $this->eur(3000)], ['class' => 'light', 'amount' => $this->eur(0)]],
        ]));

        $stored = $this->stored((string) $method->id());

        $this->assertSame(['heavy' => 3000, 'light' => 0], $stored->classRates());
        $this->assertSame(ShippingClassMode::REPLACE, $stored->classMode());
    }

    public function test_a_per_class_method_in_adjust_mode_accepts_a_discount(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->shippingClass('discount', 'Discount');

        $method = $this->writer()->create($this->zoneId, $this->input([
            'name' => 'Adjusting', 'kind' => 'per_class', 'classMode' => 'adjust',
            'classRates' => [['class' => 'heavy', 'amount' => $this->eur(2500)], ['class' => 'discount', 'amount' => $this->eur(-300)]],
        ]));

        $stored = $this->stored((string) $method->id());

        $this->assertSame(ShippingClassMode::ADJUST, $stored->classMode());
        $this->assertSame(['discount' => -300, 'heavy' => 2500], $stored->classRates());
        $this->assertSame('adjust', DB::table('shipping_methods')->where('id', $method->id())->value('class_mode'));
    }

    public function test_a_carrier_method_stores_its_code_and_no_price(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input(['name' => 'Econt', 'kind' => 'carrier', 'price' => null, 'carrierCode' => 'econt']));

        $stored = $this->stored((string) $method->id());

        $this->assertSame(ShippingMethodKind::CARRIER, $stored->kind());
        $this->assertSame('econt', $stored->carrierCode());
        $this->assertNull($stored->amountMinor());
    }

    public function test_a_new_method_is_appended_at_the_end_of_its_zones_order_even_when_the_stored_orders_have_gaps(): void
    {
        $this->assertSame(0, $this->writer()->create($this->zoneId, $this->input(['name' => 'First']))->sortOrder());

        $row = \EasyCo\Shipping\ShippingMethod::create($this->zoneId, 'Gapped', ShippingMethodKind::FLAT, 6, true, 100);
        app(ShippingMethodRepository::class)->save($row);

        $other = (string) $this->zone('Other', 1)->id();
        $this->assertSame(7, $this->writer()->create($this->zoneId, $this->input(['name' => 'Last']))->sortOrder(), 'highest + 1');
        $this->assertSame(0, $this->writer()->create($other, $this->input(['name' => 'Elsewhere']))->sortOrder(), 'each zone has its own order');
    }

    public function test_creating_in_a_zone_that_is_gone_is_a_translated_not_found(): void
    {
        try {
            $this->writer()->create('999999', $this->input());
            $this->fail('the zone is gone');
        } catch (ShippingMethodNotFoundException $exception) {
            $this->assertStringNotContainsString('shipping.methods', $exception->getMessage());
        }

        $this->assertSame(0, $this->methodCount());
    }

    // =====================================================================================================
    // Every refusal: a translated field error, nothing written
    // =====================================================================================================

    public function test_invalid_input_is_a_translated_field_error_and_writes_nothing(): void
    {
        $this->activityLogOn();
        $this->shippingClass('heavy', 'Heavy');
        $this->spyOnMethodHooks();

        $cases = [
            'name_empty' => [$this->input(['name' => '  ']), 'name'],
            'name_256' => [$this->input(['name' => str_repeat('я', 256)]), 'name'],
            'name_control' => [$this->input(['name' => "Bad\nname"]), 'name'],
            'name_bidi' => [$this->input(['name' => "Evil\u{202E}name"]), 'name'],
            'name_nul' => [$this->input(['name' => "A\0B"]), 'name'],
            'kind_unknown' => [$this->input(['kind' => 'teleport']), 'kind'],
            'flat_without_price' => [$this->input(['price' => null]), 'price'],
            'flat_negative_price' => [$this->input(['price' => $this->eur(-100)]), 'price'],
            'price_10_digits' => [$this->input(['price' => Money::fromDecimal('1000000000.00', 'EUR')]), 'price'],
            'price_other_currency' => [$this->input(['price' => Money::fromMinorUnits(500, 'USD')]), 'price'],
            'free_above_negative' => [$this->input(['freeAbove' => $this->eur(-1)]), 'free_above'],
            'free_above_too_large' => [$this->input(['freeAbove' => Money::fromDecimal('1000000000.00', 'EUR')]), 'free_above'],
            'per_class_without_price' => [$this->input(['kind' => 'per_class', 'price' => null]), 'price'],
            'mode_unknown' => [$this->input(['kind' => 'per_class', 'classMode' => 'multiply']), 'class_mode'],
            'class_unknown' => [$this->input(['kind' => 'per_class', 'classRates' => [['class' => 'ghost', 'amount' => $this->eur(100)]]]), 'class_rates'],
            'class_bad_code' => [$this->input(['kind' => 'per_class', 'classRates' => [['class' => 'Bad Code!', 'amount' => $this->eur(100)]]]), 'class_rates'],
            'class_duplicate' => [$this->input(['kind' => 'per_class', 'classRates' => [['class' => 'heavy', 'amount' => $this->eur(100)], ['class' => 'heavy', 'amount' => $this->eur(200)]]]), 'class_rates'],
            'class_negative_in_replace' => [$this->input(['kind' => 'per_class', 'classRates' => [['class' => 'heavy', 'amount' => $this->eur(-100)]]]), 'class_rates'],
            'class_not_money' => [$this->input(['kind' => 'per_class', 'classRates' => [['class' => 'heavy', 'amount' => '12.50']]]), 'class_rates'],
            'class_too_large_in_adjust' => [$this->input(['kind' => 'per_class', 'classMode' => 'adjust', 'classRates' => [['class' => 'heavy', 'amount' => Money::fromDecimal('-1000000000.00', 'EUR')]]]), 'class_rates'],
            'class_501_rows' => [$this->input(['kind' => 'per_class', 'classRates' => array_fill(0, 501, ['class' => 'heavy', 'amount' => $this->eur(1)])]), 'class_rates'],
            'carrier_without_code' => [$this->input(['kind' => 'carrier', 'price' => null, 'carrierCode' => '']), 'carrier_code'],
            'carrier_bad_code' => [$this->input(['kind' => 'carrier', 'price' => null, 'carrierCode' => 'Not A Code']), 'carrier_code'],
            'carrier_code_65' => [$this->input(['kind' => 'carrier', 'price' => null, 'carrierCode' => str_repeat('a', 65)]), 'carrier_code'],
        ];

        foreach ($cases as $label => [$input, $field]) {
            $errors = $this->refusal(fn () => $this->writer()->create($this->zoneId, $input));

            $this->assertArrayHasKey($field, $errors, "{$label}: the error is on {$field}");
            $this->assertNotSame('', $errors[$field][0], $label);
            $this->assertStringNotContainsString('ShippingMethod ', $errors[$field][0], "{$label}: the domain's raw English message is never shown");
            $this->assertStringNotContainsString('shipping.methods', $errors[$field][0], "{$label}: a translated sentence, not a key");
            $this->assertStringNotContainsString('SQLSTATE', $errors[$field][0], $label);
        }

        $this->assertSame(0, $this->methodCount(), 'nothing written');
        $this->assertSame([], $this->methodAuditRows(), 'no audit entry');
        $this->assertSame([], $this->hookCalls, 'no hook');
    }

    public function test_html_in_a_name_is_stored_as_inert_text(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input(['name' => '<script>alert(1)</script> & <b>x</b>']));

        $this->assertSame('<script>alert(1)</script> & <b>x</b>', $this->stored((string) $method->id())->name(), 'plain text is stored byte for byte; it is escaped where it is rendered');
    }

    public function test_the_refusals_are_in_bulgarian_in_bg(): void
    {
        App::setLocale('bg');

        $errors = $this->refusal(fn () => $this->writer()->create($this->zoneId, $this->input(['name' => '', 'price' => null])));

        $this->assertSame('Въведете име на метода.', $errors['name'][0]);
        $this->assertSame('Въведете цена.', $errors['price'][0]);
    }

    // =====================================================================================================
    // Update
    // =====================================================================================================

    public function test_an_update_round_trips_every_field_and_never_changes_the_order_or_the_zone(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->writer()->create($this->zoneId, $this->input(['name' => 'First']));
        $method = $this->writer()->create($this->zoneId, $this->input(['name' => 'Second']));
        $this->writer()->create($this->zoneId, $this->input(['name' => 'Third']));

        $this->writer()->update((string) $method->id(), $this->input([
            'name' => 'Renamed', 'kind' => 'per_class', 'active' => false, 'price' => $this->eur(900), 'freeAbove' => $this->eur(8000),
            'classMode' => 'adjust', 'classRates' => [['class' => 'heavy', 'amount' => $this->eur(-200)]], 'requiresPickupPoint' => true,
        ]));

        $stored = $this->stored((string) $method->id());

        $this->assertSame('Renamed', $stored->name());
        $this->assertSame(ShippingMethodKind::PER_CLASS, $stored->kind());
        $this->assertFalse($stored->isActive());
        $this->assertSame(900, $stored->amountMinor());
        $this->assertSame(8000, $stored->freeAboveMinor());
        $this->assertSame(ShippingClassMode::ADJUST, $stored->classMode());
        $this->assertSame(['heavy' => -200], $stored->classRates());
        $this->assertTrue($stored->requiresPickupPoint());
        $this->assertSame(1, $stored->sortOrder(), 'the order is not the update\'s to change');
        $this->assertSame($this->zoneId, $stored->zoneId());
        $this->assertSame(['First', 'Renamed', 'Third'], $this->methodNames($this->zoneId));
    }

    public function test_changing_the_kind_clears_what_the_old_kind_left_behind(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $method = $this->writer()->create($this->zoneId, $this->input([
            'kind' => 'per_class', 'classMode' => 'adjust', 'freeAbove' => $this->eur(5000), 'classRates' => [['class' => 'heavy', 'amount' => $this->eur(100)]],
        ]));

        $this->writer()->update((string) $method->id(), $this->input(['kind' => 'flat', 'classMode' => 'adjust', 'classRates' => [['class' => 'heavy', 'amount' => $this->eur(100)]]]));
        $flat = $this->stored((string) $method->id());
        $this->assertSame([], $flat->classRates());
        $this->assertSame(ShippingClassMode::REPLACE, $flat->classMode());
        $this->assertSame(0, DB::table('shipping_method_class_rates')->count());

        $this->writer()->update((string) $method->id(), $this->input(['kind' => 'free', 'price' => null]));
        $this->assertNull($this->stored((string) $method->id())->amountMinor());
    }

    public function test_an_invalid_update_changes_nothing(): void
    {
        $this->activityLogOn();
        $method = $this->writer()->create($this->zoneId, $this->input(['name' => 'Keep']));
        $before = DB::table('shipping_methods')->where('id', $method->id())->first();
        $auditBefore = count($this->methodAuditRows());
        $this->spyOnMethodHooks();

        $errors = $this->refusal(fn () => $this->writer()->update((string) $method->id(), $this->input(['name' => ''])));

        $this->assertArrayHasKey('name', $errors);
        $this->assertEquals($before, DB::table('shipping_methods')->where('id', $method->id())->first());
        $this->assertCount($auditBefore, $this->methodAuditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_an_update_that_changes_nothing_writes_no_audit_entry_and_fires_no_hook(): void
    {
        $this->activityLogOn();
        $method = $this->writer()->create($this->zoneId, $this->input(['name' => 'Same', 'freeAbove' => $this->eur(9000)]));
        $auditBefore = count($this->methodAuditRows());
        $this->spyOnMethodHooks();

        $this->writer()->update((string) $method->id(), $this->input(['name' => 'Same', 'freeAbove' => $this->eur(9000)]));

        $this->assertCount($auditBefore, $this->methodAuditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_updating_deleting_or_toggling_an_unknown_method_is_a_translated_not_found(): void
    {
        foreach ([
            fn () => $this->writer()->update('999999', $this->input()),
            fn () => $this->writer()->delete('999999'),
            fn () => $this->writer()->setActive('999999', false),
        ] as $call) {
            try {
                $call();
                $this->fail('an unknown method must be refused.');
            } catch (ShippingMethodNotFoundException $exception) {
                $this->assertStringNotContainsString('shipping.methods', $exception->getMessage());
            }
        }
    }

    // =====================================================================================================
    // Audit and hooks
    // =====================================================================================================

    public function test_every_write_writes_exactly_one_audit_entry_and_fires_its_hook_after_commit(): void
    {
        $this->activityLogOn();
        $baseline = $this->spyOnMethodHooks();
        $seen = [];

        foreach (['created', 'updated', 'deactivated', 'activated'] as $suffix) {
            Hook::action('shipping.method.'.$suffix, function () use (&$seen, $suffix): void {
                $seen[$suffix] = DB::table('shipping_methods')->count();
            });
        }

        $method = $this->writer()->create($this->zoneId, $this->input(['name' => 'Alpha']));
        $this->assertCount(1, $this->methodAuditRows());
        $this->assertSame(1, $seen['created'], 'after the commit the new row is visible');

        $this->writer()->update((string) $method->id(), $this->input(['name' => 'Alpha 2']));
        $this->assertCount(2, $this->methodAuditRows());

        $this->assertTrue($this->writer()->setActive((string) $method->id(), false));
        $this->assertCount(3, $this->methodAuditRows());

        $this->assertTrue($this->writer()->setActive((string) $method->id(), true));
        $this->assertCount(4, $this->methodAuditRows());

        $this->writer()->delete((string) $method->id());
        $this->assertCount(5, $this->methodAuditRows());

        $rows = $this->methodAuditRows();
        $this->assertSame(['created', 'updated', 'updated', 'updated', 'deleted'], array_map(fn ($row) => $row->action, $rows));
        $this->assertSame([null, 'method', 'active', 'active', null], array_map(fn ($row) => $row->field, $rows));
        $this->assertSame(['shipping_method'], array_values(array_unique(array_map(fn ($row) => $row->entity_type, $rows))));

        $this->assertSame(
            ['shipping.method.created', 'shipping.method.updated', 'shipping.method.deactivated', 'shipping.method.activated', 'shipping.method.deleted'],
            array_map(fn (array $call): string => $call[0], $this->hookCalls),
        );

        foreach ($this->hookCalls as [$name, , $level]) {
            $this->assertSame($baseline, $level, "{$name} must fire outside the writer's transaction");
        }
    }

    public function test_the_update_audit_entry_carries_compact_json_snapshots_before_and_after(): void
    {
        $this->activityLogOn();
        $this->shippingClass('heavy', 'Heavy');
        $method = $this->writer()->create($this->zoneId, $this->input(['name' => 'Стар метод']));

        $this->writer()->update((string) $method->id(), $this->input([
            'name' => 'Нов метод', 'kind' => 'per_class', 'classMode' => 'adjust', 'classRates' => [['class' => 'heavy', 'amount' => $this->eur(-100)]],
        ]));

        $row = collect($this->methodAuditRows())->firstWhere('field', 'method');
        $before = json_decode($row->old_value, true);
        $after = json_decode($row->new_value, true);

        $this->assertSame('Стар метод', $before['name']);
        $this->assertSame('flat', $before['kind']);
        $this->assertSame('Нов метод', $after['name']);
        $this->assertSame('adjust', $after['class_mode']);
        $this->assertSame(['heavy' => -100], $after['class_rates']);
        $this->assertStringContainsString('Нов метод', $row->new_value, 'Cyrillic is stored as itself');
    }

    public function test_the_hook_payloads(): void
    {
        $this->spyOnMethodHooks();

        $method = $this->writer()->create($this->zoneId, $this->input(['name' => 'Before']));
        $this->writer()->update((string) $method->id(), $this->input(['name' => 'After']));
        $this->writer()->setActive((string) $method->id(), false);
        $this->writer()->delete((string) $method->id());

        [$created, $updated, $deactivated, $deleted] = $this->hookCalls;

        $this->assertSame('Before', $created[1][0]->name());
        $this->assertNull($created[1][1], 'not a copy');
        $this->assertSame('After', $updated[1][0]->name());
        $this->assertSame('Before', $updated[1][1]['name']);
        $this->assertFalse($deactivated[1][0]->isActive());
        $this->assertSame('After', $deleted[1][0]['name']);
        $this->assertSame((string) $method->id(), $deleted[1][0]['id']);
    }

    public function test_a_failed_write_fires_no_hook_and_writes_no_audit_entry(): void
    {
        $this->activityLogOn();
        $this->spyOnMethodHooks();

        try {
            $this->writer()->create($this->zoneId, $this->input(['name' => '']));
        } catch (ShippingMethodInvalidException) {
            // expected
        }

        try {
            $this->writer()->create('999999', $this->input());
        } catch (ShippingMethodNotFoundException) {
            // expected
        }

        $this->assertSame([], $this->hookCalls);
        $this->assertSame([], $this->methodAuditRows());
        $this->assertSame(0, $this->methodCount());
    }

    public function test_a_listener_that_throws_cannot_undo_the_committed_write(): void
    {
        Hook::action('shipping.method.created', function (): void {
            throw new \RuntimeException('a listener failed');
        });

        try {
            $this->writer()->create($this->zoneId, $this->input(['name' => 'Durable']));
            $this->fail('the listener\'s error surfaces');
        } catch (\RuntimeException $exception) {
            $this->assertSame('a listener failed', $exception->getMessage());
        }

        $this->assertSame(1, $this->methodCount(), 'the write is already durable — the hook fires after the commit');
    }

    public function test_switching_a_method_on_or_off_when_it_already_is_writes_nothing(): void
    {
        $this->activityLogOn();
        $method = $this->writer()->create($this->zoneId, $this->input());
        $auditBefore = count($this->methodAuditRows());
        $this->spyOnMethodHooks();

        $this->assertFalse($this->writer()->setActive((string) $method->id(), true));

        $this->assertCount($auditBefore, $this->methodAuditRows());
        $this->assertSame([], $this->hookCalls);
    }

    // =====================================================================================================
    // Delete
    // =====================================================================================================

    public function test_a_method_is_deleted_with_its_class_amounts_one_audit_snapshot_and_one_hook(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $method = $this->perClassMethod($this->zoneId, 'Doomed', ['heavy' => 3000]);
        $this->spyOnMethodHooks();

        $this->writer()->delete((string) $method->id());

        $this->assertSame(0, $this->methodCount());
        $this->assertSame(0, DB::table('shipping_method_class_rates')->count(), 'the class amounts go with it');

        // A delete is written even while the activity log is off (the logger's one deliberate exception).
        $rows = $this->methodAuditRows();
        $this->assertCount(1, $rows);
        $this->assertSame('deleted', $rows[0]->action);
        $this->assertSame('Doomed', json_decode($rows[0]->old_value, true)['name']);
        $this->assertSame(['heavy' => 3000], json_decode($rows[0]->old_value, true)['class_rates']);
        $this->assertSame(['shipping.method.deleted'], array_map(fn (array $call): string => $call[0], $this->hookCalls));
    }

    public function test_nothing_references_a_method_by_foreign_key_so_a_delete_is_free_and_the_in_use_message_is_translated(): void
    {
        // Orders keep a snapshot (name, code, amount): the only foreign key into shipping_methods is the class-rates cascade.
        $this->assertSame([], DB::select("SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = 'shipping_methods' AND TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> 'shipping_method_class_rates'"));

        $exception = new ShippingMethodInUseException('Econt');

        $this->assertStringContainsString('Econt', $exception->getMessage());
        $this->assertStringNotContainsString('shipping.methods', $exception->getMessage());

        App::setLocale('bg');
        $this->assertStringContainsString('Изключете', (new ShippingMethodInUseException('Еконт'))->getMessage());
    }

    public function test_the_repository_delete_of_an_unknown_id_is_a_no_op(): void
    {
        $this->writer()->create($this->zoneId, $this->input());

        app(ShippingMethodRepository::class)->delete('424242');

        $this->assertSame(1, $this->methodCount());
    }

    // =====================================================================================================
    // The schema
    // =====================================================================================================

    public function test_the_class_mode_column_defaults_to_replace_and_the_database_checks_it(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input());
        $this->assertSame('replace', DB::table('shipping_methods')->where('id', $method->id())->value('class_mode'));

        try {
            DB::table('shipping_methods')->where('id', $method->id())->update(['class_mode' => 'multiply']);
            $this->fail('the CHECK must refuse an unknown mode.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(3819, (int) $exception->errorInfo[1], 'MySQL: check constraint violated');
        }

        try {
            DB::table('shipping_methods')->where('id', $method->id())->update(['class_mode' => 'adjust']);
            $this->fail('the CHECK must refuse adjust on a flat method.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(3819, (int) $exception->errorInfo[1], 'MySQL: check constraint violated');
        }

        $this->assertSame('replace', DB::table('shipping_methods')->where('id', $method->id())->value('class_mode'));
    }
}
