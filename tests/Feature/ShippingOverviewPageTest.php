<?php

namespace Tests\Feature;

use App\Filament\Pages\ShippingOverview;
use App\Filament\StaffPanelUser;
use App\Services\ShippingTester;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingClass;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\ShippingZone;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The read-only Shipping page (shipping-domain-design.md §12.3.5, stage 5a): WHO
 * may see it, the status block and the zone overview, and the "Try it" tool. The
 * overview is driven by real GETs; "Try it" is a Livewire form (wire:submit to
 * run()), so a test types the fields and clicks the button tests what ships.
 */
class ShippingOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(string $roleName): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create(Str::slug($roleName).'-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName.' Tester', $role);
        app(StaffRepository::class)->save($staff);

        return $this->actingAsPanelUser($staff->id());
    }

    private function actingAsPanelUser(string $staffId): StaffPanelUser
    {
        $model = StaffPanelUser::find($staffId);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    private function zone(string $name, int $sortOrder, ?array $settlements = null, ?array $postcodes = null): ShippingZone
    {
        $zone = ShippingZone::create($name, $sortOrder, ['BG'], $settlements, $postcodes);
        app(ShippingZoneRepository::class)->save($zone);

        return $zone;
    }

    private function classRow(string $code, string $name): void
    {
        app(ShippingClassRepository::class)->save(ShippingClass::create($name, $code));
    }

    /** @param array<string, int> $rates */
    private function method(
        string $zoneId,
        string $name,
        ShippingMethodKind $kind = ShippingMethodKind::FLAT,
        int $sortOrder = 0,
        ?int $amount = 500,
        array $rates = [],
        ?int $freeAbove = null,
        ?string $carrier = null,
        bool $pickup = false,
        bool $active = true,
    ): ShippingMethod {
        $method = ShippingMethod::create($zoneId, $name, $kind, $sortOrder, $active, $amount, $rates, $freeAbove, $carrier, $pickup);
        app(ShippingMethodRepository::class)->save($method);

        return $method;
    }

    /** @param list<Permission> $permissions */
    private function actingAsCustomRole(array $permissions): StaffPanelUser
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);

        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);

        return $this->actingAsPanelUser($staff->id());
    }

    // =====================================================================================================
    // Who may see it
    // =====================================================================================================

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $response = $this->get(ShippingOverview::getUrl());

        $response->assertStatus(302);
        $this->assertStringContainsString('/admin/login', (string) $response->headers->get('Location'));
    }

    public function test_an_administrator_may_see_it(): void
    {
        $this->actingAsStaff('Administrator');

        $this->get(ShippingOverview::getUrl())->assertOk();
    }

    public function test_a_manager_may_see_it(): void
    {
        $this->actingAsStaff('Manager');

        $this->get(ShippingOverview::getUrl())->assertOk();
    }

    public function test_product_entry_without_shipping_manage_is_refused_and_gets_no_navigation_item(): void
    {
        $this->actingAsStaff('Product Entry');

        $this->assertFalse(ShippingOverview::canAccess());
        $this->get(ShippingOverview::getUrl())->assertStatus(403);
    }

    public function test_a_custom_role_without_shipping_manage_is_refused(): void
    {
        $this->actingAsCustomRole([Permission::PRODUCT_VIEW]);

        $this->get(ShippingOverview::getUrl())->assertStatus(403);
    }

    // =====================================================================================================
    // The status block and the overview
    // =====================================================================================================

    public function test_the_no_zone_notice_shows_when_there_are_no_zones_and_goes_away_once_one_exists(): void
    {
        $this->actingAsStaff('Administrator');

        $this->get(ShippingOverview::getUrl())->assertOk()->assertSeeText(__('shipping.status.no_zone'));

        $this->zone('Bulgaria', 0);

        $this->get(ShippingOverview::getUrl())->assertOk()->assertDontSeeText(__('shipping.status.no_zone'));
    }

    public function test_the_status_block_counts_zones_and_active_methods(): void
    {
        $zone = $this->zone('Bulgaria', 0);
        $this->method($zone->id(), 'On', ShippingMethodKind::FLAT, 0, 400);
        $this->method($zone->id(), 'Off', ShippingMethodKind::FLAT, 1, 400, active: false);

        $this->actingAsStaff('Administrator');

        $component = Livewire::test(ShippingOverview::class);

        $this->assertSame(1, $component->viewData('zoneCount'));
        $this->assertSame(1, $component->viewData('activeMethodCount'));
        $this->assertFalse($component->viewData('noZone'));
    }

    public function test_the_overview_lists_zones_in_match_order_with_their_methods_and_coverage(): void
    {
        $narrow = $this->zone('Sofia city', 0, ['София']);
        $broad = $this->zone('Bulgaria', 1);
        $this->method($narrow->id(), 'Sofia only', ShippingMethodKind::FLAT, 0, 300);
        $this->method($broad->id(), 'Econt office', ShippingMethodKind::FLAT, 0, 400, freeAbove: 10000);

        $this->actingAsStaff('Administrator');

        $html = (string) $this->get(ShippingOverview::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('Econt office', $html);
        $this->assertStringContainsString(__('shipping.coverage.only_settlements', ['names' => 'София']), $html);
        $this->assertLessThan(strpos($html, 'Bulgaria'), strpos($html, 'Sofia city'), 'the match order, Sofia city first');

        // Numbered 1..n and the first-match line is shown.
        $this->assertStringContainsString(__('shipping.overview.zone_number', ['number' => 1]), $html);
        $this->assertStringContainsString(__('shipping.overview.first_match_wins'), $html);
    }

    public function test_a_zone_with_no_methods_says_so_neutrally(): void
    {
        $this->zone('Empty', 0);

        $this->actingAsStaff('Administrator');

        $this->get(ShippingOverview::getUrl())->assertOk()->assertSeeText(__('shipping.overview.no_methods'));
    }

    public function test_the_overview_writes_nothing(): void
    {
        $zone = $this->zone('Bulgaria', 0);
        $this->method($zone->id(), 'Econt office', ShippingMethodKind::FLAT, 0, 400);

        $this->actingAsStaff('Administrator');

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->get(ShippingOverview::getUrl())->assertOk();

        $this->assertSame([], $writes, 'the page is read-only');
    }

    public function test_the_shipping_navigation_item_issues_no_query(): void
    {
        $this->actingAsStaff('Administrator');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $items = ShippingOverview::getNavigationItems();

        $this->assertCount(1, $items);
        $this->assertSame(0, $queries, 'the navigation item adds no query to any page');
    }

    // =====================================================================================================
    // Try it
    // =====================================================================================================

    /** @param array<string, mixed> $state */
    private function tryIt(array $state): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(ShippingOverview::class)
            ->set('country', $state['country'] ?? 'BG')
            ->set('settlement', $state['settlement'] ?? '')
            ->set('postcode', $state['postcode'] ?? '')
            ->set('pickupPoint', $state['pickup'] ?? false)
            ->set('goods', $state['goods'] ?? '10.00')
            ->set('lines', $state['lines'] ?? [['class' => null, 'quantity' => 1]])
            ->call('run');
    }

    /** The owner's fixture (shipping-domain-design.md §12.9 5a): one Bulgaria zone, three methods. */
    private function ownerFixture(): ShippingZone
    {
        $zone = $this->zone('Bulgaria', 0);
        $this->method($zone->id(), 'Econt office', ShippingMethodKind::FLAT, 0, 400, freeAbove: 10000);
        $this->method($zone->id(), 'Econt address', ShippingMethodKind::FLAT, 1, 500);
        $this->method($zone->id(), 'Econt locker', ShippingMethodKind::FLAT, 2, 350, freeAbove: 10000, pickup: true);

        return $zone;
    }

    public function test_the_owner_fixture_at_76_50_prices_each_method_and_hints_the_smallest_remaining(): void
    {
        $this->ownerFixture();
        App::setLocale('en');

        $component = $this->tryIt(['settlement' => 'Sofia', 'goods' => '76.50']);

        $component->assertSet('result.matched', true);
        $component->assertSet('result.zoneName', 'Bulgaria');

        $methods = $component->get('result')['methods'];
        $this->assertSame(['Econt office', 'Econt address', 'Econt locker'], array_column($methods, 'name'));
        $this->assertSame(['4.00 €', '5.00 €', '3.50 €'], array_column($methods, 'price'));

        $hint = $component->get('result')['hint'];
        $this->assertStringContainsString('23.50', $hint);
        $this->assertStringContainsString('Econt office', $hint);
    }

    public function test_the_owner_fixture_at_100_00_is_free_for_the_threshold_methods_and_hint_is_unlocked(): void
    {
        $this->ownerFixture();
        App::setLocale('en');

        $component = $this->tryIt(['settlement' => 'Sofia', 'goods' => '100.00']);

        $methods = $component->get('result')['methods'];
        $this->assertSame(['0.00 €', '5.00 €', '0.00 €'], array_column($methods, 'price'));

        $hint = $component->get('result')['hint'];
        $this->assertStringContainsString('Econt office', $hint);
        $this->assertStringContainsString('free', $hint);
    }

    public function test_a_narrower_zone_above_the_broad_one_matches_its_own_destination(): void
    {
        $narrow = $this->zone('Sofia city', 0, ['София']);
        $broad = $this->zone('Bulgaria', 1);
        $this->method($narrow->id(), 'Sofia only', ShippingMethodKind::FLAT, 0, 300);
        $this->method($broad->id(), 'Broad', ShippingMethodKind::FLAT, 0, 500);

        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $sofia = $this->tryIt(['settlement' => 'гр. София', 'goods' => '10.00']);
        $this->assertSame('Sofia city', $sofia->get('result')['zoneName']);
        $this->assertSame('софия', $sofia->get('result')['settlement'], 'the normalised settlement is shown');

        $varna = $this->tryIt(['settlement' => 'Варна', 'goods' => '10.00']);
        $this->assertSame('Bulgaria', $varna->get('result')['zoneName']);
    }

    public function test_a_destination_with_no_zone_shows_the_refusal(): void
    {
        $this->zone('Bulgaria', 0);
        App::setLocale('en');

        $component = $this->tryIt(['country' => 'RO', 'settlement' => 'Cluj', 'goods' => '10.00']);

        $component->assertSet('result.matched', false);
        $this->assertSame([], $component->get('result')['methods']);
        $component->assertSeeText(__('shipping.try_it.refused'));
    }

    public function test_an_inactive_method_is_not_offered(): void
    {
        $zone = $this->zone('Bulgaria', 0);
        $this->method($zone->id(), 'Off', ShippingMethodKind::FLAT, 0, 400, active: false);
        $this->method($zone->id(), 'On', ShippingMethodKind::FLAT, 1, 500);

        $component = $this->tryIt(['settlement' => 'Sofia', 'goods' => '10.00']);

        $this->assertSame(['On'], array_column($component->get('result')['methods'], 'name'));
    }

    public function test_a_per_class_method_charges_the_most_expensive_class(): void
    {
        $this->classRow('heavy', 'Heavy');
        $zone = $this->zone('Bulgaria', 0);
        $this->method($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, 500, rates: ['heavy' => 3000]);

        $component = $this->tryIt(['settlement' => 'Sofia', 'goods' => '10.00', 'lines' => [['class' => 'heavy', 'quantity' => 1]]]);

        $this->assertSame('30.00 €', $component->get('result')['methods'][0]['price']);
    }

    public function test_an_unknown_class_code_falls_back_to_the_base_price(): void
    {
        $this->classRow('heavy', 'Heavy');
        $zone = $this->zone('Bulgaria', 0);
        $this->method($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 0, 500, rates: ['heavy' => 3000]);

        $component = $this->tryIt(['settlement' => 'Sofia', 'goods' => '10.00', 'lines' => [['class' => 'ghost', 'quantity' => 1]]]);

        $this->assertSame('5.00 €', $component->get('result')['methods'][0]['price']);
    }

    public function test_a_carrier_method_needs_a_carrier_quote_and_has_no_price(): void
    {
        $zone = $this->zone('Bulgaria', 0);
        $this->method($zone->id(), 'Courier', ShippingMethodKind::CARRIER, 0, null, carrier: 'econt');

        $component = $this->tryIt(['settlement' => 'Sofia', 'goods' => '10.00']);

        $method = $component->get('result')['methods'][0];
        $this->assertTrue($method['needsQuote']);
        $this->assertNull($method['price']);
    }

    public function test_the_normalised_postcode_is_shown_and_ignored_for_a_pickup_point(): void
    {
        $zone = $this->zone('Bulgaria', 0);
        $this->method($zone->id(), 'M', ShippingMethodKind::FLAT, 0, 400);

        $street = $this->tryIt(['settlement' => 'Sofia', 'postcode' => ' 1000 ', 'goods' => '10.00']);
        $this->assertSame('1000', $street->get('result')['postcode']);

        $pickup = $this->tryIt(['settlement' => 'Sofia', 'postcode' => ' 1000 ', 'pickup' => true, 'goods' => '10.00']);
        $this->assertSame('', $pickup->get('result')['postcode'], 'a pickup point has no postcode');
    }

    public function test_input_limits_are_field_errors_never_a_500(): void
    {
        $this->zone('Bulgaria', 0);
        $this->actingAsStaff('Administrator');

        $fresh = fn () => Livewire::test(ShippingOverview::class)->set('country', 'BG')->set('goods', '10.00');

        $fresh()->set('settlement', str_repeat('x', 256))->call('run')->assertHasErrors('settlement');
        $fresh()->set('postcode', str_repeat('9', 21))->call('run')->assertHasErrors('postcode');
        $fresh()->set('goods', 'abc')->call('run')->assertHasErrors('goods');
        $fresh()->set('goods', '999999999999')->call('run')->assertHasErrors('goods');
        $fresh()->set('lines', [['class' => null, 'quantity' => 0]])->call('run')->assertHasErrors('lines.0.quantity');
        $fresh()->set('lines', [['class' => null, 'quantity' => 100000]])->call('run')->assertHasErrors('lines.0.quantity');
    }

    public function test_try_it_writes_nothing_and_issues_no_handle(): void
    {
        $this->ownerFixture();
        $this->actingAsStaff('Administrator');

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $component = Livewire::test(ShippingOverview::class)
            ->set('country', 'BG')
            ->set('settlement', 'Sofia')
            ->set('goods', '76.50')
            ->set('lines', [['class' => null, 'quantity' => 1]])
            ->call('run');

        $this->assertSame([], $writes, 'Try it is read-only');

        $result = $component->get('result');
        $this->assertArrayNotHasKey('handle', $result);
        foreach ($result['methods'] as $method) {
            $this->assertArrayNotHasKey('handle', $method);
        }
    }

    // =====================================================================================================
    // The query budget
    // =====================================================================================================

    private int $lastOverviewTotal = 0;

    public function test_the_overview_reads_a_fixed_number_of_shipping_rows_for_one_and_twelve_zones(): void
    {
        $one = $this->zone('Zone 1', 0);
        $this->method($one->id(), 'M', ShippingMethodKind::FLAT, 0, 400);
        $this->actingAsStaff('Administrator');

        $oneZone = $this->measureOverviewShippingReads();
        $oneTotal = $this->lastOverviewTotal;

        for ($i = 2; $i <= 12; $i++) {
            $zone = $this->zone('Zone '.$i, $i - 1);
            $this->method($zone->id(), 'M'.$i, ShippingMethodKind::FLAT, 0, 400);
        }

        $twelveZones = $this->measureOverviewShippingReads();
        $twelveTotal = $this->lastOverviewTotal;

        fwrite(STDERR, sprintf(
            "\n[query-count] shipping overview: 1 zone %d shipping reads (%d total), 12 zones %d shipping reads (%d total)\n",
            $oneZone, $oneTotal, $twelveZones, $twelveTotal,
        ));

        $this->assertSame(4, $oneZone, 'the zones, the methods, their rates and the classes');
        $this->assertSame($oneZone, $twelveZones, 'the overview never reads per zone');
    }

    private function measureOverviewShippingReads(): int
    {
        $shipping = 0;
        $total = 0;

        DB::listen(function ($query) use (&$shipping, &$total): void {
            $total++;
            if (str_contains($query->sql, 'shipping_')) {
                $shipping++;
            }
        });

        $this->get(ShippingOverview::getUrl())->assertOk();

        DB::flushQueryLog();

        $this->lastOverviewTotal = $total;

        return $shipping;
    }

    public function test_the_tester_reads_a_fixed_number_of_shipping_rows(): void
    {
        $zone = $this->ownerFixture();
        $this->classRow('heavy', 'Heavy');

        // A FLAT-only zone needs no class names at all.
        $flatOnly = $this->measureTesterShippingReads();

        // A PER_CLASS method adds exactly one class-name read, never one per method.
        $this->method($zone->id(), 'By class', ShippingMethodKind::PER_CLASS, 3, 500, rates: ['heavy' => 3000]);

        $withClass = $this->measureTesterShippingReads();

        fwrite(STDERR, sprintf(
            "\n[query-count] Try it tester: FLAT-only %d shipping reads, with a PER_CLASS method %d\n",
            $flatOnly, $withClass,
        ));

        $this->assertSame(3, $flatOnly, 'the zones, the matched zone methods and their rates');
        $this->assertSame(4, $withClass, 'plus the one class-name read a PER_CLASS method needs');
    }

    private function measureTesterShippingReads(): int
    {
        $shipping = 0;

        DB::listen(function ($query) use (&$shipping): void {
            if (str_contains($query->sql, 'shipping_')) {
                $shipping++;
            }
        });

        app(ShippingTester::class)->run('BG', 'Sofia', null, false, 7650, [new RateLine(null, 1)]);

        DB::flushQueryLog();

        return $shipping;
    }
}
