<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\ShippingMethodCopier;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodReorderer;
use App\Services\ShippingMethodWriter;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5f: the method WRITER carries the optional courier and delivery type — validated as plain text and a known
 * value, stored NULL when empty, in the audit snapshot and the hook payloads, copied with the method, and never lost
 * by a toggle or a reorder. A no-op update still writes nothing.
 */
class ShippingMethodGroupingWriterTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function input(array $overrides = []): ShippingMethodInput
    {
        $input = array_merge([
            'name' => 'To office',
            'kind' => 'flat',
            'price' => Money::fromMinorUnits(500, 'EUR'),
        ], $overrides);

        // Stage 6a: an office or locker method is pickup-only (label x scope), so the old form's toggle must be on for it.
        if (! array_key_exists('requiresPickupPoint', $input) && in_array($input['deliveryType'] ?? null, ['office', 'locker'], true)) {
            $input['requiresPickupPoint'] = true;
        }

        return new ShippingMethodInput(...$input);
    }

    /** @return array<string, list<string>> */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ShippingMethodInvalidException $exception) {
            return $exception->errors;
        }

        $this->fail('the write should have been refused');
    }

    private function row(string $id): object
    {
        return DB::table('shipping_methods')->where('id', $id)->first();
    }

    // ---- create ---------------------------------------------------------------------------------------------

    public function test_a_method_is_created_with_a_trimmed_courier_and_a_delivery_type(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input(['courier' => '  Еконт ', 'deliveryType' => 'office']));

        $this->assertSame('Еконт', $method->courier());
        $this->assertSame(ShippingDeliveryType::OFFICE, $method->deliveryType());
        $row = $this->row((string) $method->id());
        $this->assertSame(['Еконт', 'office'], [$row->courier, $row->delivery_type]);

        $loaded = app(ShippingMethodRepository::class)->findById((string) $method->id());
        $this->assertSame(['Еконт', ShippingDeliveryType::OFFICE], [$loaded->courier(), $loaded->deliveryType()]);
    }

    public function test_every_delivery_type_and_every_kind_is_accepted_including_a_carrier_method(): void
    {
        foreach (['address', 'office', 'locker', 'other'] as $type) {
            $this->assertSame($type, $this->writer()->create($this->zoneId, $this->input(['name' => $type, 'courier' => 'Econt', 'deliveryType' => $type]))->deliveryType()->value);
        }

        $free = $this->writer()->create($this->zoneId, $this->input(['name' => 'Free', 'kind' => 'free', 'price' => null, 'courier' => 'Econt', 'deliveryType' => 'locker']));
        $carrier = $this->writer()->create($this->zoneId, $this->input(['name' => 'Carrier', 'kind' => 'carrier', 'price' => null, 'carrierCode' => 'econt', 'courier' => 'Econt', 'deliveryType' => 'office']));

        $this->assertSame('Econt', $free->courier());
        $this->assertSame([ 'Econt', ShippingDeliveryType::OFFICE], [$carrier->courier(), $carrier->deliveryType()]);
    }

    public function test_a_blank_or_whitespace_only_courier_and_an_empty_type_are_stored_as_null(): void
    {
        foreach ([[null, null], ['', ''], ["  \t ", '  ']] as [$courier, $type]) {
            $method = $this->writer()->create($this->zoneId, $this->input(['courier' => $courier, 'deliveryType' => $type]));
            $row = $this->row((string) $method->id());

            $this->assertNull($row->courier);
            $this->assertNull($row->delivery_type);
        }
    }

    public function test_a_method_without_the_fields_is_exactly_as_before(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input());

        $this->assertNull($method->courier());
        $this->assertNull($method->deliveryType());
    }

    public function test_every_refusal_is_keyed_translated_and_writes_nothing(): void
    {
        $this->activityLogOn();
        $this->spyOnMethodHooks();

        $cases = [
            'a 1000-character courier' => [['courier' => str_repeat('x', 1000)], 'courier'],
            '101 characters' => [['courier' => str_repeat('я', 101)], 'courier'],
            'a script tag is text, but a newline is not' => [['courier' => "<script>\nalert(1)</script>"], 'courier'],
            'a control character' => [['courier' => "Eco\x07nt"], 'courier'],
            'NUL' => [['courier' => "Eco\x00nt"], 'courier'],
            'a bidi override' => [['courier' => "Eco\u{202E}nt"], 'courier'],
            'an unknown delivery type' => [['deliveryType' => 'drone'], 'delivery_type'],
            'a delivery type in the wrong case' => [['deliveryType' => 'OFFICE'], 'delivery_type'],
        ];

        foreach ($cases as $label => [$override, $field]) {
            $errors = $this->refusal(fn () => $this->writer()->create($this->zoneId, $this->input($override)));

            $this->assertArrayHasKey($field, $errors, $label);
            $this->assertStringNotContainsString('shipping.methods', $errors[$field][0], $label);
        }

        $this->assertSame(0, DB::table('shipping_methods')->count());
        $this->assertSame([], $this->methodAuditRows());
        $this->assertSame([], $this->hookCalls);

        App::setLocale('bg');
        $this->assertSame('Изберете вид доставка от списъка или го оставете празен.', $this->refusal(fn () => $this->writer()->create($this->zoneId, $this->input(['deliveryType' => 'drone'])))['delivery_type'][0]);
    }

    public function test_html_in_a_courier_is_stored_as_text(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input(['courier' => '<b>Econt</b> & Co']));

        $this->assertSame('<b>Econt</b> & Co', $this->row((string) $method->id())->courier);
    }

    public function test_exactly_a_hundred_characters_are_accepted(): void
    {
        $this->assertSame(100, mb_strlen($this->writer()->create($this->zoneId, $this->input(['courier' => str_repeat('я', 100)]))->courier()));
    }

    // ---- update, no-op, audit and hook --------------------------------------------------------------------------

    public function test_an_update_changes_both_with_one_audit_entry_and_the_hook_payloads_carry_them(): void
    {
        $this->activityLogOn();
        $method = $this->writer()->create($this->zoneId, $this->input(['courier' => 'Econt', 'deliveryType' => 'office']));
        $baseline = $this->spyOnMethodHooks();
        $auditBefore = count($this->methodAuditRows());

        $this->writer()->update((string) $method->id(), $this->input(['courier' => 'Speedy', 'deliveryType' => 'locker']));

        $rows = array_slice($this->methodAuditRows(), $auditBefore);
        $this->assertCount(1, $rows, 'exactly one audit entry');
        $before = json_decode($rows[0]->old_value, true);
        $after = json_decode($rows[0]->new_value, true);
        $this->assertSame(['Econt', 'office'], [$before['courier'], $before['delivery_type']]);
        $this->assertSame(['Speedy', 'locker'], [$after['courier'], $after['delivery_type']]);

        $this->assertSame(['shipping.method.updated'], array_column($this->hookCalls, 0));
        $this->assertSame($baseline, $this->hookCalls[0][2]);
        $this->assertSame(['Econt', 'office'], [$this->hookCalls[0][1][1]['courier'], $this->hookCalls[0][1][1]['delivery_type']], 'the hook carries the snapshot from before');
        $this->assertSame('Speedy', $this->hookCalls[0][1][0]->courier());
    }

    public function test_changing_only_the_courier_or_only_the_type_is_a_change(): void
    {
        $this->activityLogOn();
        $method = $this->writer()->create($this->zoneId, $this->input(['courier' => 'Econt', 'deliveryType' => 'office']));
        $this->spyOnMethodHooks();

        $this->writer()->update((string) $method->id(), $this->input(['courier' => 'Econt Express', 'deliveryType' => 'office']));
        $this->writer()->update((string) $method->id(), $this->input(['courier' => 'Econt Express', 'deliveryType' => '']));

        $this->assertSame(['shipping.method.updated', 'shipping.method.updated'], array_column($this->hookCalls, 0));
        $this->assertNull($this->row((string) $method->id())->delivery_type);
    }

    public function test_an_update_that_changes_nothing_writes_nothing_and_fires_no_hook(): void
    {
        $this->activityLogOn();
        $method = $this->writer()->create($this->zoneId, $this->input(['courier' => 'Econt', 'deliveryType' => 'office']));
        $auditBefore = count($this->methodAuditRows());
        $this->spyOnMethodHooks();

        // the same values, typed with spaces: still nothing
        $this->writer()->update((string) $method->id(), $this->input(['courier' => ' Econt ', 'deliveryType' => 'office']));

        $this->assertCount($auditBefore, $this->methodAuditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_a_refused_update_leaves_the_stored_values(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input(['courier' => 'Econt', 'deliveryType' => 'office']));

        $this->refusal(fn () => $this->writer()->update((string) $method->id(), $this->input(['courier' => "Bad\nname", 'deliveryType' => 'locker'])));

        $row = $this->row((string) $method->id());
        $this->assertSame(['Econt', 'office'], [$row->courier, $row->delivery_type]);
    }

    public function test_the_created_audit_and_the_deleted_snapshot_carry_both_fields(): void
    {
        $this->activityLogOn();
        $method = $this->writer()->create($this->zoneId, $this->input(['courier' => 'Econt', 'deliveryType' => 'address']));
        $this->spyOnMethodHooks();

        $this->writer()->delete((string) $method->id());

        $deleted = array_values(array_filter($this->methodAuditRows(), fn ($row) => $row->action === 'deleted'));
        $snapshot = json_decode($deleted[0]->old_value, true);
        $this->assertSame(['Econt', 'address'], [$snapshot['courier'], $snapshot['delivery_type']]);
        $this->assertSame(['Econt', 'address'], [$this->hookCalls[0][1][0]['courier'], $this->hookCalls[0][1][0]['delivery_type']]);
    }

    // ---- other writers keep the fields ----------------------------------------------------------------------

    public function test_switching_a_method_off_and_on_keeps_the_courier_and_type(): void
    {
        $method = $this->writer()->create($this->zoneId, $this->input(['courier' => 'Econt', 'deliveryType' => 'locker']));

        $this->writer()->setActive((string) $method->id(), false);
        $this->writer()->setActive((string) $method->id(), true);

        $row = $this->row((string) $method->id());
        $this->assertSame(['Econt', 'locker'], [$row->courier, $row->delivery_type]);
    }

    public function test_reordering_keeps_the_courier_and_type_of_every_method(): void
    {
        $a = $this->writer()->create($this->zoneId, $this->input(['name' => 'A', 'courier' => 'Econt', 'deliveryType' => 'office']));
        $b = $this->writer()->create($this->zoneId, $this->input(['name' => 'B', 'courier' => 'Speedy', 'deliveryType' => 'address']));

        app(ShippingMethodReorderer::class)->moveUp((string) $b->id());

        $this->assertSame(['Speedy', 'address'], [$this->row((string) $b->id())->courier, $this->row((string) $b->id())->delivery_type]);
        $this->assertSame(['Econt', 'office'], [$this->row((string) $a->id())->courier, $this->row((string) $a->id())->delivery_type]);
    }

    public function test_a_copy_carries_the_courier_and_type_to_every_zone(): void
    {
        $source = $this->writer()->create($this->zoneId, $this->input(['courier' => 'Econt', 'deliveryType' => 'office']));
        $one = (string) $this->zone('Romania', 1)->id();
        $two = (string) $this->zone('Greece', 2)->id();
        $this->spyOnMethodHooks();

        app(ShippingMethodCopier::class)->copyToZones((string) $source->id(), [$one, $two]);

        $copies = DB::table('shipping_methods')->whereIn('zone_id', [$one, $two])->get();
        $this->assertCount(2, $copies);

        foreach ($copies as $copy) {
            $this->assertSame(['Econt', 'office'], [$copy->courier, $copy->delivery_type]);
        }

        $this->assertSame(['shipping.method.created', 'shipping.method.created'], array_column($this->hookCalls, 0));
        $this->assertSame('Econt', $this->hookCalls[0][1][0]->courier());
    }

    public function test_a_label_and_a_pickup_toggle_that_disagree_are_refused_on_the_delivery_type_field_and_nothing_is_saved(): void
    {
        // Stage 6a replaced "the pickup flag is never derived from the delivery type" (label x scope, design 9.2.3): an office
        // method that is not pickup-only, and an address method that is, are both refused instead of stored.
        $before = DB::table('shipping_methods')->count();

        $office = $this->refusal(fn () => $this->writer()->create($this->zoneId, $this->input(['courier' => 'Econt', 'deliveryType' => 'office', 'requiresPickupPoint' => false])));
        $address = $this->refusal(fn () => $this->writer()->create($this->zoneId, $this->input(['name' => 'Addr', 'courier' => 'Econt', 'deliveryType' => 'address', 'requiresPickupPoint' => true])));

        $this->assertSame(['delivery_type'], array_keys($office));
        $this->assertSame(['delivery_type'], array_keys($address));
        $this->assertSame($before, DB::table('shipping_methods')->count());
    }

    public function test_the_check_constraint_refuses_an_unknown_type_in_the_database(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('the CHECK exists on MySQL/MariaDB only');
        }

        $method = $this->writer()->create($this->zoneId, $this->input(['requiresPickupPoint' => true]));  // pickup-only, so the later locker label is coherent (stage 6a)

        try {
            DB::table('shipping_methods')->where('id', $method->id())->update(['delivery_type' => 'drone']);
            $this->fail('the CHECK should have refused it');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(3819, (int) $exception->errorInfo[1], 'MySQL: check constraint violated');
        }

        DB::table('shipping_methods')->where('id', $method->id())->update(['delivery_type' => 'locker', 'courier' => null]);
        $this->assertSame('locker', $this->row((string) $method->id())->delivery_type);
    }
}
