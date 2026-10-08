<?php

namespace Tests\Feature;

use App\Filament\Pages\ShippingOverview;
use App\Filament\Resources\ShippingMethodResource;
use App\Filament\Resources\ShippingMethodResource\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\ListShippingMethods;
use App\Services\ShippingMethodSummaryReader;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5f: the courier and delivery type on the methods screens — the two form fields with the datalist of the
 * couriers already used, the list columns, the pickup-point convenience, the summary text ("Econt · to office") and
 * the "Try it" result grouped by courier. The existing 5d pins are untouched.
 */
class ShippingMethodGroupingResourceTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function grouped(string $zoneId, string $name, ?string $courier, ?ShippingDeliveryType $type, int $sort, int $amount = 500, ?int $freeAbove = null, bool $pickup = false): ShippingMethod
    {
        $method = ShippingMethod::create($zoneId, $name, ShippingMethodKind::FLAT, $sort, true, $amount, [], $freeAbove, null, $pickup, ShippingClassMode::REPLACE, $courier, $type);
        app(ShippingMethodRepository::class)->save($method);

        return $method;
    }

    private function model(string $id): \EasyCo\Shipping\Persistence\Eloquent\ShippingMethodModel
    {
        return ShippingMethodResource::getEloquentQuery()->where('shipping_methods.id', $id)->firstOrFail();
    }

    // ---- the form -------------------------------------------------------------------------------------------

    public function test_the_form_has_the_two_optional_fields_a_fact_line_and_the_help_link_in_both_languages(): void
    {
        $this->zone('Z', 0);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(CreateShippingMethod::class)
            ->assertFormFieldExists('courier')
            ->assertFormFieldExists('delivery_type');
        $html = html_entity_decode($page->html());

        $this->assertStringContainsString('Courier (optional)', $html);
        $this->assertStringContainsString('Delivery type (optional)', $html);
        $this->assertStringContainsString('Customers first choose the courier, then the delivery type. Methods without a courier are listed on their own.', $html);
        $this->assertStringContainsString('/admin/help/shipping#action-method-grouping', $html);
        foreach (['To address', 'To office', 'To locker', 'Other'] as $option) {
            $this->assertStringContainsString($option, $html);
        }
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);

        App::setLocale('bg');
        $bg = html_entity_decode(Livewire::test(CreateShippingMethod::class)->html());
        $this->assertStringContainsString('Куриер (по избор)', $bg);
        $this->assertStringContainsString('Клиентите първо избират куриер, после вида доставка.', $bg);
        $this->assertStringContainsString('До офис', $bg);
    }

    public function test_a_method_is_created_with_a_courier_and_a_type_through_the_form_and_kept_by_the_edit_form(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['zone_id' => $zone, 'name' => 'To office', 'kind' => 'flat', 'price' => '4,50', 'courier' => '  Econt ', 'delivery_type' => 'office'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('shipping.methods.notice.created'));

        $row = DB::table('shipping_methods')->first();
        $this->assertSame(['Econt', 'office'], [$row->courier, $row->delivery_type]);

        Livewire::test(EditShippingMethod::class, ['record' => $row->id])
            ->assertFormSet(['courier' => 'Econt', 'delivery_type' => 'office'])
            ->fillForm(['courier' => 'Speedy', 'delivery_type' => 'address'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = DB::table('shipping_methods')->first();
        $this->assertSame(['Speedy', 'address'], [$row->courier, $row->delivery_type]);
    }

    public function test_the_form_shows_the_service_refusals_on_the_two_fields(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        foreach ([
            ['courier' => str_repeat('x', 1000), 'field' => 'courier'],
            ['courier' => "Eco\x07nt", 'field' => 'courier'],
            ['courier' => "Eco\u{202E}nt", 'field' => 'courier'],
            ['courier' => "Eco\nnt", 'field' => 'courier'],
            ['delivery_type' => 'drone', 'field' => 'delivery_type'],
        ] as $case) {
            $field = $case['field'];
            unset($case['field']);

            Livewire::test(CreateShippingMethod::class)
                ->fillForm(array_merge(['zone_id' => $zone, 'name' => 'X', 'kind' => 'flat', 'price' => '1'], $case))
                ->call('create')
                ->assertHasFormErrors([$field]);
        }

        $this->assertSame(0, DB::table('shipping_methods')->count());
    }

    public function test_choosing_office_or_locker_ticks_the_pickup_box_and_address_does_not_untick_it(): void
    {
        $this->zone('Z', 0);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(CreateShippingMethod::class)->assertFormSet(['requires_pickup_point' => false]);

        $page->fillForm(['delivery_type' => 'office'])->assertFormSet(['requires_pickup_point' => true]);
        $page->fillForm(['requires_pickup_point' => false])->assertFormSet(['requires_pickup_point' => false]);
        $page->fillForm(['delivery_type' => 'locker'])->assertFormSet(['requires_pickup_point' => true]);
        $page->fillForm(['delivery_type' => 'address'])->assertFormSet(['requires_pickup_point' => true]);

        $this->assertStringContainsString(
            'Choosing office or locker ticks this box for convenience',
            html_entity_decode($page->html()),
        );
    }

    public function test_the_datalist_offers_the_couriers_in_use_once_escaped_and_capped_at_fifty(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->grouped($zone, 'A', 'Econt', ShippingDeliveryType::OFFICE, 0);
        $this->grouped($zone, 'B', 'econt', ShippingDeliveryType::ADDRESS, 1);
        $this->grouped($zone, 'C', '"><script>alert(1)</script>', null, 2);
        $this->grouped($zone, 'D', null, null, 3);
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(CreateShippingMethod::class)->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertSame(['"><script>alert(1)</script>', 'Econt'], ShippingMethodResource::courierSuggestions(), 'distinct (case-insensitively), alphabetical, no empty');

        for ($i = 1; $i <= 70; $i++) {
            $this->grouped($zone, "M{$i}", sprintf('Courier %03d', $i), null, 10 + $i);
        }

        $this->assertCount(50, ShippingMethodResource::courierSuggestions());
    }

    public function test_the_datalist_is_one_distinct_read_on_the_form_page_only(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->grouped($zone, 'A', 'Econt', ShippingDeliveryType::OFFICE, 0);
        $this->actingAsStaff('Administrator');

        $count = function (string $page) {
            $reads = 0;
            DB::listen(function ($query) use (&$reads): void {
                if (str_contains($query->sql, 'distinct') && str_contains($query->sql, '`courier`')) {
                    $reads++;
                }
            });
            Livewire::test($page);

            return $reads;
        };

        $this->assertSame(0, $count(ListShippingMethods::class), 'the list page never reads the suggestions');
        $this->assertSame(1, $count(CreateShippingMethod::class));
    }

    // ---- the list ---------------------------------------------------------------------------------------------

    public function test_the_list_shows_a_courier_and_a_delivery_type_column_in_the_unchanged_order(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->grouped($zone, 'Econt to office', 'Econt', ShippingDeliveryType::OFFICE, 0);
        $this->grouped($zone, 'Plain', null, null, 1);
        $this->grouped($zone, 'Speedy to address', 'Speedy', ShippingDeliveryType::ADDRESS, 2);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(ListShippingMethods::class);
        $html = html_entity_decode($page->html());

        $this->assertStringContainsString('Courier (optional)', $html);
        $this->assertStringContainsString('Delivery type (optional)', $html);
        $this->assertStringContainsString('To office', $html);
        $this->assertSame(['Econt to office', 'Plain', 'Speedy to address'], ShippingMethodResource::getEloquentQuery()->pluck('name')->all(), 'the method order is unchanged');

        // the summary column does not repeat what the two columns show
        $this->assertStringNotContainsString('Econt · to office', $html);

        App::setLocale('bg');
        $bg = html_entity_decode(Livewire::test(ListShippingMethods::class)->html());
        $this->assertStringContainsString('До адрес', $bg);
    }

    public function test_the_list_reads_the_same_queries_for_3_and_12_grouped_methods_as_before(): void
    {
        $this->actingAsStaff('Administrator');
        $zone = (string) $this->zone('Z', 0)->id();

        for ($i = 1; $i <= 3; $i++) {
            $this->grouped($zone, "Method {$i}", "Courier {$i}", ShippingDeliveryType::OFFICE, $i);
        }
        $three = $this->listReads();

        for ($i = 4; $i <= 12; $i++) {
            $this->grouped($zone, "Method {$i}", "Courier {$i}", ShippingDeliveryType::LOCKER, $i);
        }
        $twelve = $this->listReads();

        fwrite(STDERR, sprintf("\n[query-count] shipping methods list with couriers: 3 methods %d queries, 12 methods %d queries\n", $three, $twelve));
        $this->assertSame($three, $twelve, 'the courier columns add no query per row');
    }

    private function listReads(): int
    {
        $this->app->forgetScopedInstances();
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        Livewire::test(ListShippingMethods::class)->assertOk();

        return $count;
    }

    // ---- the summary, the overview and "Try it" -------------------------------------------------------------------

    public function test_the_summary_reader_prints_the_courier_and_the_type_from_the_lang_files(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $reader = app(ShippingMethodSummaryReader::class);

        $office = $this->grouped($zone, 'M', 'Econt', ShippingDeliveryType::OFFICE, 0);
        $onlyCourier = $this->grouped($zone, 'N', 'Econt', null, 1);
        $onlyType = $this->grouped($zone, 'O', null, ShippingDeliveryType::LOCKER, 2);
        $plain = $this->grouped($zone, 'P', null, null, 3);

        $this->assertSame('Econt · to office', $reader->grouping($office));
        $this->assertSame('Econt', $reader->grouping($onlyCourier));
        $this->assertSame('to locker', $reader->grouping($onlyType));
        $this->assertNull($reader->grouping($plain));

        $this->assertSame('Econt · to office; 5.00 €', $reader->summary($office));
        $this->assertSame('5.00 €', $reader->summary($plain), 'a method without them reads exactly as before');
        $this->assertSame('5.00 €', $reader->summary($office, null, withGrouping: false));

        App::setLocale('bg');
        $this->assertSame('Econt · до офис', $reader->grouping($office));
    }

    public function test_the_overview_shows_the_grouping_in_the_method_sentences(): void
    {
        $zone = (string) $this->zone('Bulgaria', 0)->id();
        $this->grouped($zone, 'Office', 'Econt', ShippingDeliveryType::OFFICE, 0);
        $this->actingAsStaff('Administrator');

        $html = html_entity_decode((string) $this->get(ShippingOverview::getUrl())->assertOk()->getContent());

        $this->assertStringContainsString('Econt · to office; 5.00 €', $html);
    }

    public function test_try_it_shows_the_result_grouped_by_courier_with_the_ungrouped_last(): void
    {
        $zone = (string) $this->zone('Bulgaria', 0)->id();
        $this->grouped($zone, 'To office', 'Econt', ShippingDeliveryType::OFFICE, 0, 400);
        $this->grouped($zone, 'In the shop', null, null, 1, 0);
        $this->grouped($zone, 'To address', 'econt', ShippingDeliveryType::ADDRESS, 2, 700);
        $this->grouped($zone, 'To office', 'Speedy', ShippingDeliveryType::OFFICE, 3, 450);
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(ShippingOverview::class)
            ->set('country', 'BG')->set('settlement', '')->set('postcode', '')->set('pickupPoint', false)->set('goods', '10.00')
            ->set('lines', [['class' => null, 'quantity' => 1]])
            ->call('run');

        $html = html_entity_decode($component->html());
        $html = substr($html, (int) strpos($html, __('shipping.try_it.result_heading'))); // the result only, not the overview above it
        $positions = [strpos($html, 'Econt</p>'), strpos($html, 'Speedy</p>'), strpos($html, 'Other methods</p>')];

        $this->assertNotContains(false, $positions, 'a heading per courier and one for the rest');
        $this->assertSame($positions, [min($positions), $positions[1], max($positions)], 'Econt, Speedy, then the ungrouped');
        $this->assertLessThan(strpos($html, 'In the shop'), strpos($html, 'Speedy</p>'));
    }

    public function test_try_it_shows_no_group_headings_when_no_method_has_a_courier(): void
    {
        $zone = (string) $this->zone('Bulgaria', 0)->id();
        $this->grouped($zone, 'Flat', null, null, 0, 500);
        $this->actingAsStaff('Administrator');

        $html = html_entity_decode(Livewire::test(ShippingOverview::class)
            ->set('country', 'BG')->set('goods', '10.00')->set('lines', [['class' => null, 'quantity' => 1]])
            ->call('run')->html());

        $this->assertStringNotContainsString('Other methods</p>', $html);
        $this->assertStringContainsString('Flat', $html);
    }

    // ---- money is untouched ---------------------------------------------------------------------------------------

    public function test_the_stored_prices_are_what_the_form_typed_whatever_the_grouping(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['zone_id' => $zone, 'name' => 'To locker', 'kind' => 'flat', 'price' => '3,50', 'free_above' => '50', 'courier' => 'BoxNow', 'delivery_type' => 'locker'])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = DB::table('shipping_methods')->first();
        $this->assertSame([350, 5000], [(int) $row->amount_minor, (int) $row->free_above_minor]);
        $this->assertEquals(Money::fromMinorUnits(350, 'EUR'), Money::fromMinorUnits((int) $row->amount_minor, 'EUR'));
    }
}
