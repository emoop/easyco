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
 * Shipping stage 6d / 6d2 (shipping-domain-design.md 9.2.8-9.2.12): the admin "Delivers to" Select — the ONE field that
 * replaces the delivery type and the destination scope — the five-way text in the methods table, the helper line, the
 * summary sentence and "Try it", and the bg/en strings. The rule itself is the domain's (ShippingDestinationScope /
 * ShippingMethod::servesPickupPoint()); nothing here re-implements it.
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

    public function test_every_delivers_to_option_creates_its_exact_label_and_scope_pair(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        // The mapping table of the owner's decision (stage 6d2): option -> the (label, scope) pair it stores.
        $expected = [
            'any' => [null, 'any'],
            'address' => ['address', 'address'],
            'pickup' => [null, 'pickup'],
            'office' => ['office', 'pickup'],
            'locker' => ['locker', 'pickup'],
        ];

        foreach (array_keys($expected) as $deliversTo) {
            Livewire::test(CreateShippingMethod::class)
                ->fillForm(['zone_id' => $zone, 'name' => 'M '.$deliversTo, 'kind' => 'flat', 'price' => '4,50', 'delivers_to' => $deliversTo])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $rows = DB::table('shipping_methods')->orderBy('sort_order')->get(['name', 'delivery_type', 'destination_scope']);

        $this->assertSame(array_map(static fn (string $key): string => 'M '.$key, array_keys($expected)), $rows->pluck('name')->all());
        $this->assertSame(array_column($expected, 0), $rows->pluck('delivery_type')->all());
        $this->assertSame(array_column($expected, 1), $rows->pluck('destination_scope')->all());
    }

    public function test_an_unknown_delivers_to_value_is_refused_by_the_form_and_nothing_is_written(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        // delivers_to is a UI value with a fixed five-option list: anything else is a form error, never a stored method.
        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['zone_id' => $zone, 'name' => 'Bad', 'kind' => 'flat', 'price' => '4', 'delivers_to' => 'everywhere'])
            ->call('create')
            ->assertHasFormErrors(['delivers_to']);

        $this->assertSame(0, DB::table('shipping_methods')->count());
    }

    /** The six (label, scope) pairs a stored method can hold (the DB CHECK allows nothing else) and how each reads. */
    public static function legacyPairs(): array
    {
        return [
            'address + address' => [ShippingDeliveryType::ADDRESS, ShippingDestinationScope::ADDRESS, 'address'],
            'office + pickup' => [ShippingDeliveryType::OFFICE, ShippingDestinationScope::PICKUP, 'office'],
            'locker + pickup' => [ShippingDeliveryType::LOCKER, ShippingDestinationScope::PICKUP, 'locker'],
            'no label + pickup' => [null, ShippingDestinationScope::PICKUP, 'pickup'],
            'no label + address' => [null, ShippingDestinationScope::ADDRESS, 'address'],
            'no label + any' => [null, ShippingDestinationScope::ANY, 'any'],
        ];
    }

    public function test_every_legacy_pair_opens_as_its_delivers_to_value_and_keeps_its_scope_when_saved_unchanged(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        foreach (static::legacyPairs() as $case => [$label, $scope, $deliversTo]) {
            $created = $this->method($zone, 'M '.$deliversTo, $scope, $label);
            [$storedLabel] = ShippingMethodResource::labelAndScopeFrom($deliversTo);

            Livewire::test(EditShippingMethod::class, ['record' => (string) $created->id()])
                ->assertFormSet(['delivers_to' => $deliversTo])
                ->call('save')
                ->assertHasNoFormErrors();

            $row = DB::table('shipping_methods')->where('id', $created->id())->first();
            $this->assertSame($scope->value, $row->destination_scope, $case.': the scope is kept');
            $this->assertSame($storedLabel, $row->delivery_type, $case.': the label is the one the table stores for that option');
        }
    }

    public function test_a_method_labelled_other_and_any_opens_as_any_and_saves_with_no_label(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $created = $this->method($zone, 'Legacy other', ShippingDestinationScope::ANY, ShippingDeliveryType::OTHER);
        $this->actingAsStaff('Administrator');

        Livewire::test(EditShippingMethod::class, ['record' => (string) $created->id()])
            ->assertFormSet(['delivers_to' => 'any'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = DB::table('shipping_methods')->where('id', $created->id())->first();
        $this->assertNull($row->delivery_type, '"Other" is gone: the stored label becomes null');
        $this->assertSame('any', $row->destination_scope);
    }

    public function test_the_methods_table_shows_the_five_delivers_to_texts_in_both_languages(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $this->method($zone, 'Any', ShippingDestinationScope::ANY, null, 0);
        $this->method($zone, 'Addr', ShippingDestinationScope::ADDRESS, ShippingDeliveryType::ADDRESS, 1);
        $this->method($zone, 'Pick', ShippingDestinationScope::PICKUP, null, 2);
        $this->method($zone, 'Off', ShippingDestinationScope::PICKUP, ShippingDeliveryType::OFFICE, 3);
        $this->method($zone, 'Lock', ShippingDestinationScope::PICKUP, ShippingDeliveryType::LOCKER, 4);
        $this->actingAsStaff('Administrator');

        App::setLocale('en');
        $en = html_entity_decode(Livewire::test(ListShippingMethods::class)->html());

        foreach (['All (address, office, locker)', 'Address only', 'Office or locker only', 'Office only', 'Locker only'] as $text) {
            $this->assertStringContainsString($text, $en);
        }

        App::setLocale('bg');
        $bg = html_entity_decode(Livewire::test(ListShippingMethods::class)->html());

        foreach (['Всички (адрес, офис, автомат)', 'Само адрес', 'Само офис или автомат', 'Само офис', 'Само автомат'] as $text) {
            $this->assertStringContainsString($text, $bg);
        }
    }

    public function test_the_helper_line_follows_the_choice_in_both_languages(): void
    {
        $this->zone('Z', 0);
        $this->actingAsStaff('Administrator');

        $en = [
            'any' => 'The method delivers to an address, an office and a locker.',
            'address' => 'The method delivers to an address only.',
            'pickup' => 'The method delivers to an office or a locker only.',
            'office' => 'The method delivers to an office only.',
            'locker' => 'The method delivers to a locker only.',
        ];
        $bg = [
            'any' => 'Методът доставя до адрес, офис и автомат.',
            'address' => 'Методът доставя само до адрес.',
            'pickup' => 'Методът доставя само до офис и автомат.',
            'office' => 'Методът доставя само до офис.',
            'locker' => 'Методът доставя само до автомат.',
        ];

        App::setLocale('en');
        $page = Livewire::test(CreateShippingMethod::class);

        foreach ($en as $value => $line) {
            $this->assertSame($line, ShippingMethodResource::deliversToHelp($value));
            $this->assertStringContainsString($line, html_entity_decode($page->fillForm(['delivers_to' => $value])->html()));
        }

        // A missing or unknown value explains the default instead of printing a raw lang key.
        $this->assertSame($en['any'], ShippingMethodResource::deliversToHelp(null));
        $this->assertSame($en['any'], ShippingMethodResource::deliversToHelp('nonsense'));

        App::setLocale('bg');

        foreach ($bg as $value => $line) {
            $this->assertSame($line, ShippingMethodResource::deliversToHelp($value));
        }

        // The rendered default line in Bulgarian: a Livewire interaction re-applies the store locale, so only a fresh
        // mount renders in bg (the five bg OPTION texts are asserted in the form test next door).
        $this->assertStringContainsString('Методът доставя до адрес, офис и автомат.', html_entity_decode(Livewire::test(CreateShippingMethod::class)->html()));
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
