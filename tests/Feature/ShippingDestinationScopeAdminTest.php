<?php

namespace Tests\Feature;

use App\Filament\Pages\ShippingOverview;
use App\Filament\Resources\ShippingMethodResource;
use App\Filament\Resources\ShippingMethodResource\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\ListShippingMethods;
use App\Services\ShippingMethodSummaryReader;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Shipping stage 6d (shipping-domain-design.md 9.2.8-9.2.12): the admin "Serves" Select and the label x scope coupling,
 * the scope words in the methods table, the summary sentence and "Try it", and the bg/en strings. The rule itself is the
 * domain's (ShippingDestinationScope / ShippingMethod::servesPickupPoint()); nothing here re-implements it.
 */
final class ShippingDestinationScopeAdminTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function method(string $zoneId, string $name, ShippingDestinationScope $scope, ?ShippingDeliveryType $label = null, int $sort = 0, int $amount = 500): ShippingMethod
    {
        $method = ShippingMethod::create($zoneId, $name, ShippingMethodKind::FLAT, $sort, true, $amount, [], null, null, false, ShippingClassMode::REPLACE, null, $label, $scope);
        app(ShippingMethodRepository::class)->save($method);

        return $method;
    }

    /** @return \Livewire\Features\SupportTesting\Testable */
    private function tryIt(bool $pickup): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(ShippingOverview::class)
            ->set('country', 'BG')
            ->set('settlement', 'Sofia')
            ->set('postcode', '')
            ->set('pickupPoint', $pickup)
            ->set('goods', '10.00')
            ->set('lines', [['class' => null, 'quantity' => 1]])
            ->call('run');
    }

    public function test_each_valid_label_and_scope_pair_is_created_through_the_form(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        foreach ([
            ['name' => 'To address', 'delivery_type' => 'address', 'destination_scope' => 'address'],
            ['name' => 'To office', 'delivery_type' => 'office', 'destination_scope' => 'pickup'],
            ['name' => 'Anywhere', 'delivery_type' => null, 'destination_scope' => 'any'],
        ] as $pair) {
            Livewire::test(CreateShippingMethod::class)
                ->fillForm([
                    'zone_id' => $zone, 'name' => $pair['name'], 'kind' => 'flat', 'price' => '4,50',
                    'delivery_type' => $pair['delivery_type'], 'destination_scope' => $pair['destination_scope'],
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(
            ['address', 'pickup', 'any'],
            DB::table('shipping_methods')->orderBy('sort_order')->pluck('destination_scope')->all(),
        );
    }

    public function test_a_label_and_a_scope_that_disagree_are_refused_by_the_form_and_nothing_is_written(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        // The label forces pickup-only, so the Select offers that one option and its own rule refuses any other value:
        // a tampered payload is a form error and nothing is written. (The entity's label x scope rule is the backstop.)
        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['zone_id' => $zone, 'name' => 'Bad', 'kind' => 'flat', 'price' => '4', 'delivery_type' => 'office'])
            ->fillForm(['destination_scope' => 'address'])
            ->call('create')
            ->assertHasFormErrors(['destination_scope']);

        $this->assertSame(0, DB::table('shipping_methods')->count());
    }

    public function test_an_existing_any_method_saved_without_touching_the_select_stays_any(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $created = $this->method($zone, 'Anywhere', ShippingDestinationScope::ANY);
        $this->actingAsStaff('Administrator');

        Livewire::test(EditShippingMethod::class, ['record' => (string) $created->id()])
            ->assertFormSet(['destination_scope' => 'any'])
            ->fillForm(['name' => 'Anywhere (renamed)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = DB::table('shipping_methods')->where('id', $created->id())->first();
        $this->assertSame('any', $row->destination_scope);
        $this->assertSame('Anywhere (renamed)', $row->name);
    }

    public function test_the_methods_table_shows_the_scope_words_in_both_languages(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->method($zone, 'Addr', ShippingDestinationScope::ADDRESS, ShippingDeliveryType::ADDRESS, 0);
        $this->method($zone, 'Pick', ShippingDestinationScope::PICKUP, ShippingDeliveryType::OFFICE, 1);
        $this->method($zone, 'Any', ShippingDestinationScope::ANY, null, 2);
        $this->actingAsStaff('Administrator');

        App::setLocale('en');
        $en = html_entity_decode(Livewire::test(ListShippingMethods::class)->html());
        $this->assertStringContainsString('Address only', $en);
        $this->assertStringContainsString('Pickup point only', $en);
        $this->assertStringContainsString('Serves any destination', $en);

        App::setLocale('bg');
        $bg = html_entity_decode(Livewire::test(ListShippingMethods::class)->html());
        $this->assertStringContainsString('Само адрес', $bg);
        $this->assertStringContainsString('Само офис или автомат', $bg);
        $this->assertStringContainsString('Обслужва и адрес, и офис/автомат', $bg);
    }

    public function test_the_summary_sentence_states_the_scope_in_both_languages(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $reader = app(ShippingMethodSummaryReader::class);

        $address = $this->method($zone, 'A', ShippingDestinationScope::ADDRESS, ShippingDeliveryType::ADDRESS, 0);
        $pickup = $this->method($zone, 'P', ShippingDestinationScope::PICKUP, ShippingDeliveryType::OFFICE, 1);
        $any = $this->method($zone, 'X', ShippingDestinationScope::ANY, null, 2);

        App::setLocale('en');
        $this->assertStringContainsString('Address only', $reader->summary($address));
        $this->assertStringContainsString('Pickup point only', $reader->summary($pickup));
        $this->assertStringContainsString('Serves any destination', $reader->summary($any));

        App::setLocale('bg');
        $this->assertStringContainsString('Само адрес', $reader->summary($address));
        $this->assertStringContainsString('Само офис или автомат', $reader->summary($pickup));
        $this->assertStringContainsString('Обслужва и адрес, и офис/автомат', $reader->summary($any));
    }

    public function test_try_it_marks_the_methods_that_do_not_serve_the_tested_destination(): void
    {
        $zone = (string) $this->zone('Bulgaria', 0)->id();
        $this->method($zone, 'Econt address', ShippingDestinationScope::ADDRESS, ShippingDeliveryType::ADDRESS, 0);
        $this->method($zone, 'Econt locker', ShippingDestinationScope::PICKUP, ShippingDeliveryType::LOCKER, 1);
        $this->method($zone, 'Econt both', ShippingDestinationScope::ANY, null, 2);

        App::setLocale('en');

        // A street address: the locker method (pickup-only) does not serve it, and is named with its scope.
        $street = $this->tryIt(false);
        $this->assertSame([true, false, true], array_column($street->get('result')['methods'], 'serves'));
        $this->assertSame(['Econt locker (Pickup point only)'], $street->get('result')['unserved']);

        // A pickup point: the address-only method does not serve it.
        $pickup = $this->tryIt(true);
        $this->assertSame([false, true, true], array_column($pickup->get('result')['methods'], 'serves'));
        $this->assertSame(['Econt address (Address only)'], $pickup->get('result')['unserved']);
    }

    public function test_the_two_languages_carry_the_same_shipping_keys(): void
    {
        $en = $this->flattenKeys(require base_path('lang/en/shipping.php'));
        $bg = $this->flattenKeys(require base_path('lang/bg/shipping.php'));

        sort($en);
        sort($bg);

        $this->assertSame($en, $bg, 'lang/bg/shipping.php must translate every key, and invent none');
    }

    /** @return list<string> */
    private function flattenKeys(array $values, string $prefix = ''): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }
}
