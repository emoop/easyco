<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\LocaleSettings;
use App\Filament\StaffPanelUser;
use App\Services\FreeShippingThreshold;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodWriter;
use App\Services\ShippingZoneReorderer;
use App\Services\ShippingZoneWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P7-2: the ONE number behind the card badge «Безплатна доставка» (FreeShippingThreshold): the lowest free-shipping threshold of the active local methods of the store country's broad zone; none when the zone has an active
 * FREE method, no country or no zone. It is cached WITHOUT expiry and recomputed when a method or a zone is written (the `shipping.*` hooks) or the store country is saved; read from the cache otherwise (0 statements).
 */
final class FreeShippingThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SiteSettingsRepository::class)->set('site.country', 'BG');
    }

    private function zone(string $name = 'Bulgaria', array $countries = ['BG']): string
    {
        return (string) app(ShippingZoneWriter::class)->create($name, $countries, [], [])->id();
    }

    private function method(string $zone, ?int $freeAbove, string $kind = 'flat', bool $active = true, string $name = 'Courier'): string
    {
        $input = new ShippingMethodInput(
            name: $name,
            kind: $kind,
            active: $active,
            price: $kind === 'free' ? null : Money::fromMinorUnits(500, 'EUR'),
            freeAbove: $freeAbove === null ? null : Money::fromMinorUnits($freeAbove, 'EUR'),
        );

        return (string) app(ShippingMethodWriter::class)->create($zone, $input)->id();
    }

    private function current(): ?int
    {
        return app(FreeShippingThreshold::class)->current()['minor'] ?? null;
    }

    private function staff(): void
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
    }

    /** ★ the rule: the LOWEST threshold of the active local methods of the broad zone, in the default currency */
    public function test_the_threshold_is_the_lowest_of_the_active_local_methods(): void
    {
        $zone = $this->zone();
        $this->method($zone, 10000, name: 'A');
        $this->method($zone, 6000, name: 'B');
        $this->method($zone, 3000, active: false, name: 'Off');
        $this->method($zone, null, name: 'No threshold');

        $this->assertSame(['minor' => 6000, 'currency' => 'EUR'], app(FreeShippingThreshold::class)->current());
    }

    public function test_there_is_none_without_a_method_a_zone_or_a_country(): void
    {
        $this->assertNull($this->current(), 'no zone');

        $zone = $this->zone();
        $this->assertNull($this->current(), 'a zone with no method');

        $this->method($zone, null);
        $this->assertNull($this->current(), 'no method carries a threshold');

        $this->method($zone, 5000, name: 'With');
        $this->assertSame(5000, $this->current());

        app(SiteSettingsRepository::class)->forget('site.country');
        $this->assertNull(app(FreeShippingThreshold::class)->refresh(), 'no store country');
    }

    /** ★ an ACTIVE method with the price FREE: shipping is free for everyone, the badge would say nothing; an inactive one changes nothing */
    public function test_an_active_free_method_means_no_threshold(): void
    {
        $zone = $this->zone();
        $this->method($zone, 5000);
        $this->assertSame(5000, $this->current());

        $free = $this->method($zone, null, 'free', name: 'Free');
        $this->assertNull($this->current(), 'a free method is active');

        app(ShippingMethodWriter::class)->setActive($free, false);
        $this->assertSame(5000, $this->current(), 'switched off: the threshold is back');
    }

    /** ★ read from the cache: the second read costs no statement; a cold cache computes it once and stores it with no expiry */
    public function test_it_is_cached_without_expiry(): void
    {
        $this->method($this->zone(), 5000);

        $this->assertSame(['minor' => 5000, 'currency' => 'EUR'], Cache::get(FreeShippingThreshold::KEY), 'the write already stored it');

        $statements = 0;
        DB::listen(function () use (&$statements): void {
            $statements++;
        });
        $this->assertSame(5000, $this->current());
        $this->assertSame(0, $statements, 'a warm read costs no statement');

        Cache::forget(FreeShippingThreshold::KEY);
        $this->assertSame(5000, $this->current());
        $this->assertGreaterThan(0, $statements, 'a cold cache computes it');
        $statements = 0;
        $this->assertSame(5000, $this->current());
        $this->assertSame(0, $statements, 'and stores it again');
    }

    /** ★ refreshed by every write that can change it: the methods, the zones (and their order) and the store country */
    public function test_it_is_refreshed_when_methods_zones_or_the_country_are_saved(): void
    {
        $zone = $this->zone('First');
        $methodId = $this->method($zone, 8000, name: 'A');
        $this->assertSame(8000, $this->current(), 'method created');

        app(ShippingMethodWriter::class)->update($methodId, new ShippingMethodInput(name: 'A', kind: 'flat', price: Money::fromMinorUnits(500, 'EUR'), freeAbove: Money::fromMinorUnits(7000, 'EUR')));
        $this->assertSame(7000, $this->current(), 'method updated');

        app(ShippingMethodWriter::class)->setActive($methodId, false);
        $this->assertNull($this->current(), 'method switched off');

        app(ShippingMethodWriter::class)->setActive($methodId, true);
        $this->assertSame(7000, $this->current(), 'method switched on');

        // a second zone with the same country: the zone order decides which one the broad match takes
        $second = $this->zone('Second');
        $this->method($second, 3000, name: 'B');
        $this->assertSame(7000, $this->current(), 'the first zone still matches first');

        $this->assertTrue(app(ShippingZoneReorderer::class)->moveUp($second));
        $this->assertSame(3000, $this->current(), 'zones reordered');

        app(ShippingZoneWriter::class)->update($second, 'Second', ['RO'], [], []);
        $this->assertSame(7000, $this->current(), 'zone updated (no longer the store country)');

        app(ShippingMethodWriter::class)->delete($methodId);
        $this->assertNull($this->current(), 'method deleted');

        app(ShippingZoneWriter::class)->delete($zone);
        $this->assertNull($this->current(), 'zone deleted');

        // the store country: the second zone now serves RO
        $this->staff();
        Livewire::test(LocaleSettings::class)->fillForm(['locale' => 'en', 'country' => 'RO', 'timezone' => 'Europe/Berlin'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(3000, $this->current(), 'store country saved');
    }

    /** the cart's hint and the badge read the SAME zone: one shared reader */
    public function test_the_cart_hint_and_the_threshold_share_one_zone_reader(): void
    {
        $this->assertSame(
            \App\Services\StoreBroadZoneMethods::class,
            (new \ReflectionClass(\App\Services\CartFreeShippingHint::class))->getConstructor()->getParameters()[0]->getType()->getName(),
        );
        $this->assertSame(
            \App\Services\StoreBroadZoneMethods::class,
            (new \ReflectionClass(FreeShippingThreshold::class))->getConstructor()->getParameters()[0]->getType()->getName(),
        );
    }
}
