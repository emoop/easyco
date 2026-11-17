<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Mail\OrderConfirmationContent;
use App\Storefront\Orders\OrderConfirmationUrl;
use App\Support\BgPostcodes;
use App\Support\BgRegions;
use App\Support\OrderDeliveryGeo;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\Feature\Account\Concerns\MakesAccountOrders;
use Tests\TestCase;

/**
 * ORD-PC: «city postcode» everywhere the address is shown; the region from the postcode (street address) or from the settlement name (office / locker: no postcode is
 * stored for those and none is invented) in the ADMIN delivery block only, with two muted notes; the 28-oblast table; the «Съвпада с профил» card collapsed; the page's
 * small text one step larger.
 */
class OrderPostcodeRegionTest extends TestCase
{
    use BuildsRefundableOrders;
    use DrivesAccountApi;
    use MakesAccountOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        app()->setLocale('bg');
    }

    // ---- the table and the data file -------------------------------------------------------------------------------------------

    public function test_the_table_has_the_28_oblasti_and_every_postcode_of_the_data_file_maps_to_one_of_them(): void
    {
        $this->assertCount(28, BgRegions::NAMES);
        $this->assertCount(28, array_unique(BgRegions::NAMES), 'no name twice');
        $this->assertNotSame(BgRegions::name('SOF'), BgRegions::name('SFO'), 'София-град and София област are two regions');
        $this->assertSame('Хасково', BgRegions::name('HKV'));

        $data = require resource_path('data/bg-postcodes.php');
        $this->assertGreaterThan(4000, count($data['p']));

        foreach ($data['p'] as $postcode => $pairs) {
            $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $postcode);

            foreach ($pairs as [$settlement, $code]) {
                $this->assertNotSame('', $settlement);
                $this->assertArrayHasKey($code, BgRegions::NAMES, "postcode {$postcode}: unknown region code {$code}");
            }
        }

        $this->assertEqualsCanonicalizing(array_keys(BgRegions::NAMES), BgPostcodes::allRegionCodes(), 'every oblast has at least one postcode');
        $this->assertLessThan(400 * 1024, filesize(resource_path('data/bg-postcodes.php')), 'the data file stays small');
    }

    public function test_the_names_are_compared_after_trim_case_prefix_and_transliteration(): void
    {
        $this->assertSame(BgPostcodes::normalise('Харманли'), BgPostcodes::normalise('Harmanli'));
        $this->assertSame(BgPostcodes::normalise('Харманли'), BgPostcodes::normalise('  ХАРМАНЛИ '));
        $this->assertSame(BgPostcodes::normalise('Харманли'), BgPostcodes::normalise('гр. Харманли'));
        $this->assertSame(BgPostcodes::normalise('Лясково'), BgPostcodes::normalise('с. Lyaskovo'));
        $this->assertNotSame(BgPostcodes::normalise('Харманли'), BgPostcodes::normalise('София'));
    }

    // ---- the geo lines (pure) --------------------------------------------------------------------------------------------------

    public function test_a_street_address_gets_its_region_and_no_note_when_everything_matches(): void
    {
        $geo = OrderDeliveryGeo::forStreet('BG', 'Харманли', '6450', null, 'bg');

        $this->assertSame('Хасково', $geo['region']);
        $this->assertNull($geo['possible']);
        $this->assertSame([], $geo['notes']);

        // the Latin spelling of the city is the same settlement
        $this->assertSame([], OrderDeliveryGeo::forStreet('BG', 'Harmanli', '6450', null, 'bg')['notes']);
        // «гр.» is ignored
        $this->assertSame([], OrderDeliveryGeo::forStreet('BG', 'гр. Харманли', '6450', null, 'bg')['notes']);
    }

    public function test_a_city_that_is_not_one_of_the_codes_settlements_gets_the_note_with_the_codes_settlement(): void
    {
        $geo = OrderDeliveryGeo::forStreet('BG', 'Харманли', '1000', null, 'bg');

        $this->assertSame('София-град', $geo['region']);
        $this->assertSame(['Кодът е на друго населено място: София'], $geo['notes']);
    }

    public function test_a_typed_region_that_differs_gets_the_note_and_one_that_agrees_does_not(): void
    {
        $this->assertSame(['Клиентът е написал област: Пловдив'], OrderDeliveryGeo::forStreet('BG', 'Харманли', '6450', 'Пловдив', 'bg')['notes']);

        foreach (['Хасково', 'област Хасково', 'Haskovo', 'Haskovo oblast'] as $typed) {
            $this->assertSame([], OrderDeliveryGeo::forStreet('BG', 'Харманли', '6450', $typed, 'bg')['notes'], $typed);
        }

        // «София» agrees with the capital region (the typed word is the start of the name) and not with Пловдив
        $this->assertSame([], OrderDeliveryGeo::forStreet('BG', 'София', '1000', 'София', 'bg')['notes']);
    }

    public function test_no_region_for_a_foreign_country_an_unknown_code_or_a_bad_code(): void
    {
        $none = ['region' => null, 'possible' => null, 'notes' => []];

        $this->assertSame($none, OrderDeliveryGeo::forStreet('GR', 'Haskovo', '6450', null, 'bg'));
        $this->assertSame($none, OrderDeliveryGeo::forStreet('BG', 'Харманли', '9999', null, 'bg'), 'an unknown code');
        $this->assertSame($none, OrderDeliveryGeo::forStreet('BG', 'Харманли', '64500', null, 'bg'), 'not 4 digits');
        $this->assertSame($none, OrderDeliveryGeo::forStreet('BG', 'Харманли', null, null, 'bg'));
        $this->assertSame(['region' => null, 'possible' => null], OrderDeliveryGeo::forPickup('GR', 'Харманли'));
    }

    public function test_the_capital_and_the_province_around_it_are_different_regions(): void
    {
        $this->assertSame('София-град', OrderDeliveryGeo::forStreet('BG', 'София', '1000', null, 'bg')['region']);
        $this->assertSame('София област', OrderDeliveryGeo::forStreet('BG', 'Самоков', '2000', null, 'bg')['region']);
    }

    public function test_a_postcode_shared_by_two_regions_is_settled_by_the_city(): void
    {
        // 2624: Цървище (Кюстендил) and Църквище (София област)
        $this->assertSame('Кюстендил', OrderDeliveryGeo::forStreet('BG', 'Цървище', '2624', null, 'bg')['region']);
        $this->assertSame('София област', OrderDeliveryGeo::forStreet('BG', 'Църквище', '2624', null, 'bg')['region']);

        $unsettled = OrderDeliveryGeo::forStreet('BG', 'Другаде', '2624', null, 'bg');
        $this->assertNull($unsettled['region']);
        $this->assertSame('Кюстендил, София област', $unsettled['possible']);
    }

    public function test_an_office_settlement_maps_to_one_several_or_no_region(): void
    {
        $this->assertSame(['region' => 'Хасково', 'possible' => null], OrderDeliveryGeo::forPickup('BG', 'Харманли'));
        $this->assertSame(['region' => 'Хасково', 'possible' => null], OrderDeliveryGeo::forPickup('BG', 'гр. Harmanli'));

        $several = OrderDeliveryGeo::forPickup('BG', 'Банкя');                       // София-град and Перник in the data
        $this->assertNull($several['region']);
        $this->assertSame('Перник, София-град', $several['possible']);

        $many = OrderDeliveryGeo::forPickup('BG', 'Лозен');                          // five regions: all five listed, none cut
        $this->assertSame(substr_count((string) $many['possible'], ',') + 1, 5);

        $this->assertSame(['region' => null, 'possible' => null], OrderDeliveryGeo::forPickup('BG', 'Несъществуващово'));
        $this->assertSame(['region' => null, 'possible' => null], OrderDeliveryGeo::forPickup('BG', null));
    }

    public function test_more_than_five_regions_are_cut_with_an_ellipsis(): void
    {
        // a name found in at least six regions in the data file
        $found = null;
        $by = [];

        foreach ((require resource_path('data/bg-postcodes.php'))['p'] as $pairs) {
            foreach ($pairs as [$name, $code]) {
                $by[$name][$code] = 1;
            }
        }

        foreach ($by as $name => $codes) {
            if (count($codes) > 5) {
                $found = $name;
                break;
            }
        }

        if ($found === null) {
            $this->markTestSkipped('no settlement name with more than five regions in the data file');
        }

        $possible = OrderDeliveryGeo::forPickup('BG', $found)['possible'];
        $this->assertStringEndsWith(', …', (string) $possible);
        $this->assertSame(5, substr_count((string) $possible, ',') );
    }

    // ---- the admin delivery block ----------------------------------------------------------------------------------------------

    private function block(array $attributes): string
    {
        return (new ReflectionMethod(OrderResource::class, 'deliveryAddressHtml'))->invoke(null, new OrderModel($attributes));
    }

    private function lines(string $html): array
    {
        return array_map(static fn (string $line): string => trim(html_entity_decode(strip_tags($line))), explode('<br>', $html));
    }

    public function test_the_admin_block_of_a_street_order_reads_city_postcode_then_the_region_then_the_country(): void
    {
        $html = $this->block(['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Харманли', 'postal_code' => '6450', 'address_line_1' => 'ул. Васил Левски 52']);

        $this->assertSame(['ул. Васил Левски 52', 'Харманли 6450', 'Хасково', 'BG'], $this->lines($html));
    }

    public function test_the_admin_block_shows_the_two_notes_muted_and_keeps_a_typed_region_when_nothing_is_derived(): void
    {
        $html = $this->block(['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Харманли', 'postal_code' => '1000', 'address_line_1' => 'ул. Тест 1', 'region' => 'Пловдив']);

        $this->assertSame(['ул. Тест 1', 'Харманли 1000', 'София-град', 'Кодът е на друго населено място: София', 'Клиентът е написал област: Пловдив', 'BG'], $this->lines($html));
        $this->assertStringContainsString('opacity: 0.65', $html, 'the notes are muted');

        // an unknown code: no derived line, the customer's own region stays as it was
        $unknown = $this->block(['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Харманли', 'postal_code' => '9999', 'address_line_1' => 'ул. Тест 1', 'region' => 'Хасково']);
        $this->assertSame(['ул. Тест 1', 'Харманли 9999', 'Хасково', 'BG'], $this->lines($unknown));
    }

    /** the owner's example */
    public function test_the_admin_block_of_an_office_derives_the_region_from_the_settlement_and_shows_no_postcode(): void
    {
        $html = $this->block(['delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'speedy', 'pickup_point_address' => 'Ул. Васил Левски 52', 'pickup_point_kind' => 'office', 'settlement' => 'Харманли']);

        $lines = $this->lines($html);
        $this->assertContains('Харманли', $lines);
        $this->assertContains('Хасково (по населеното място)', $lines);
        $this->assertSame('BG', end($lines));
        $this->assertStringNotContainsString('6450', $html, 'a postcode is never invented for an office');
        $this->assertDoesNotMatchRegularExpression('/\b\d{4}\b/', strip_tags($html));
    }

    public function test_the_admin_block_of_an_office_lists_the_possible_regions_or_shows_nothing(): void
    {
        $several = $this->lines($this->block(['delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'speedy', 'pickup_point_address' => 'X 1', 'settlement' => 'Банкя']));
        $this->assertContains('Възможни области: Перник, София-град', $several);

        $none = $this->block(['delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'speedy', 'pickup_point_address' => 'X 1', 'settlement' => 'Несъществуващово']);
        $this->assertStringNotContainsString('област', $none);
        $this->assertStringNotContainsString('населеното място', $none);
    }

    // ---- the storefront page, the mail and the API: «city postcode», never the region, never an office postcode --------------------

    private function pageOf(string $orderId): string
    {
        return $this->get(app(OrderConfirmationUrl::class)->forOrder($orderId))->assertOk()->getContent();
    }

    private function mailOf(string $orderId): string
    {
        $mail = app(OrderConfirmationContent::class)->build((object) ['related_id' => $orderId, 'locale' => 'en']);

        return $mail->html."\n".$mail->text;
    }

    public function test_a_street_order_with_a_postcode_reads_city_postcode_on_the_page_the_mail_and_the_api_and_an_order_without_one_is_unchanged(): void
    {
        $me = $this->verifiedAccount('me@example.com');
        $this->actingAs($me, 'customer');
        $with = $this->makeOrder('me@example.com', (string) $me->getKey(), city: 'Харманли', postcode: '6450');
        $without = $this->makeOrder('me@example.com', (string) $me->getKey(), city: 'Sofia', postcode: null);

        $this->assertStringContainsString('Харманли 6450', $this->pageOf($with));
        $this->assertStringContainsString('Харманли 6450', $this->mailOf($with));
        $this->assertContains('Харманли 6450', $this->getJson('/api/account/orders/'.$with)->assertOk()->json('data.delivery.lines'));

        // an order without a postcode prints exactly the city, as before
        $this->assertContains('Sofia', $this->getJson('/api/account/orders/'.$without)->assertOk()->json('data.delivery.lines'));
        $this->assertStringNotContainsString('Sofia 0', $this->pageOf($without));
    }

    /** ★ the derived region is ADMIN ONLY: not on the page, not in the mail, not in the API */
    public function test_the_derived_region_and_an_office_postcode_are_never_on_the_page_the_mail_or_the_api(): void
    {
        $me = $this->verifiedAccount('me@example.com');
        $this->actingAs($me, 'customer');
        $street = $this->makeOrder('me@example.com', (string) $me->getKey(), city: 'Харманли', postcode: '6450');
        $office = $this->makeOrder('me@example.com', (string) $me->getKey(), pickupSettlement: 'Харманли');

        foreach ([$street, $office] as $id) {
            $page = $this->pageOf($id);
            $mail = $this->mailOf($id);
            $api = json_encode($this->getJson('/api/account/orders/'.$id)->assertOk()->json('data'), JSON_UNESCAPED_UNICODE);

            foreach (['page' => $page, 'mail' => $mail, 'api' => $api] as $where => $text) {
                $this->assertStringNotContainsString('Хасково', $text, "the derived region must not be in the {$where}");
                $this->assertStringNotContainsString('по населеното място', $text, "{$where}");
                $this->assertStringNotContainsString('Possible regions', $text, "{$where}");
            }
        }

        // an office order: no postcode anywhere (it has none)
        $this->assertStringNotContainsString('6450', $this->pageOf($office));
        $this->assertStringNotContainsString('6450', $this->mailOf($office));
        $this->assertStringNotContainsString('6450', (string) json_encode($this->getJson('/api/account/orders/'.$office)->json()));
        $this->assertStringContainsString('Харманли', $this->pageOf($office), 'the settlement itself is still shown');
    }

    // ---- the admin page: the matches card collapsed, the type one step larger -------------------------------------------------------

    private function actingAsAdmin(): void
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Admin Tester', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
    }

    private function viewHtml(string $orderId): string
    {
        return Livewire::test(ViewOrder::class, ['record' => $orderId])->html();
    }

    public function test_the_matches_card_is_collapsed_with_a_muted_line_that_says_whether_anything_matches(): void
    {
        $this->actingAsAdmin();
        $alone = $this->refundableOrder([['quantity' => 1, 'unit' => 1000]]);

        $html = $this->viewHtml($alone['orderId']);
        $this->assertStringContainsString('Няма съвпадения', $html);
        $this->assertStringNotContainsString('Има съвпадения', $html);

        // a second order of the same person (the fixture's email is the same for every order)
        $other = $this->refundableOrder([['quantity' => 1, 'unit' => 1000]]);
        $html = $this->viewHtml($other['orderId']);
        $this->assertStringContainsString('Има съвпадения', $html);

        // the card is Filament's own collapsed section (no custom JS): the title, then the collapsed state in the same section
        $this->assertMatchesRegularExpression('/Съвпадения.{0,3000}?isCollapsed|fi-collapsed.{0,3000}?Съвпадения|Съвпадения.{0,3000}?fi-collapsed/s', $html);
    }

    public function test_the_order_page_carries_its_own_scope_class_and_the_one_step_type_rule(): void
    {
        $this->actingAsAdmin();
        $order = $this->refundableOrder([['quantity' => 1, 'unit' => 1000]]);

        $html = $this->viewHtml($order['orderId']);

        $this->assertStringContainsString('ord-view', $html);
        $this->assertStringContainsString('.fi-page.ord-view{--text-xs:.875rem;--text-xs--line-height:calc(1.25 / .875);--text-sm:1rem;--text-sm--line-height:1.5}', $html);
        // the inline sizes of the page are one step (0.125rem) larger than they were
        $this->assertStringContainsString('font-size: 0.8125rem', $html);
        $this->assertStringNotContainsString('font-size: 0.6875rem', $html);
    }
}
