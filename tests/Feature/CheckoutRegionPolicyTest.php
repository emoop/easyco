<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\CheckoutSettings;
use App\Filament\StaffPanelUser;
use App\Settings\CheckoutRegionPolicy;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Exceptions\CheckoutRegionPolicyMalformedException;
use App\Settings\RegionMode;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Checkout RN-1 (design 2.2-2.3, 5): the one reader of the region rule and the settings page that writes it. */
class CheckoutRegionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): CheckoutRegionPolicy
    {
        return app(CheckoutRegionPolicy::class);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function staff(string $roleName = 'Administrator'): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create(Str::slug($roleName).'-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    // ---- the reader ----------------------------------------------------------------------------------------------------------------

    public function test_nothing_stored_means_optional_for_every_country(): void
    {
        $this->assertSame(RegionMode::OPTIONAL, $this->policy()->defaultMode());
        $this->assertSame([], $this->policy()->overrides());
        $this->assertSame(RegionMode::OPTIONAL, $this->policy()->modeFor('BG'));
        $this->assertSame(['default' => 'optional', 'countries' => []], $this->policy()->all());
    }

    public function test_the_default_and_a_per_country_override(): void
    {
        $this->settings()->set(CheckoutRegionPolicy::DEFAULT_KEY, 'required');
        $this->settings()->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"BG":"hidden","US":"optional"}');

        $this->assertSame(RegionMode::HIDDEN, $this->policy()->modeFor('BG'));
        $this->assertSame(RegionMode::OPTIONAL, $this->policy()->modeFor('US'));
        $this->assertSame(RegionMode::REQUIRED, $this->policy()->modeFor('DE'), 'a country the owner did not name gets the default');
        $this->assertSame(['default' => 'required', 'countries' => ['BG' => 'hidden', 'US' => 'optional']], $this->policy()->all());
    }

    public function test_a_country_is_normalised_to_upper_case_on_the_way_in_and_on_the_way_out(): void
    {
        $this->settings()->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"bg":"hidden"}');

        $this->assertSame(RegionMode::HIDDEN, $this->policy()->modeFor('bg'));
        $this->assertSame(RegionMode::HIDDEN, $this->policy()->modeFor(' Bg '));
        $this->assertSame(['BG' => 'hidden'], $this->policy()->all()['countries']);
    }

    /** @return array<string, array{string, string}> */
    public static function malformed(): array
    {
        return [
            'default: an unknown mode' => [CheckoutRegionPolicy::DEFAULT_KEY, 'sometimes'],
            'countries: not JSON' => [CheckoutRegionPolicy::COUNTRIES_KEY, '{not json'],
            'countries: a JSON list' => [CheckoutRegionPolicy::COUNTRIES_KEY, '["BG"]'],
            'countries: a JSON scalar' => [CheckoutRegionPolicy::COUNTRIES_KEY, '"hidden"'],
            'countries: an unknown mode' => [CheckoutRegionPolicy::COUNTRIES_KEY, '{"BG":"sometimes"}'],
            'countries: a non-string mode' => [CheckoutRegionPolicy::COUNTRIES_KEY, '{"BG":5}'],
            'countries: an unknown country' => [CheckoutRegionPolicy::COUNTRIES_KEY, '{"ZZ":"hidden"}'],
            'countries: not a country code' => [CheckoutRegionPolicy::COUNTRIES_KEY, '{"Bulgaria":"hidden"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function test_a_malformed_stored_value_throws_never_a_silent_default(string $key, string $value): void
    {
        $this->settings()->set($key, $value);

        $this->expectException(CheckoutRegionPolicyMalformedException::class);
        $this->policy()->modeFor('BG');
    }

    public function test_a_malformed_value_also_stops_a_checkout_loudly(): void
    {
        $this->settings()->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"ZZ":"hidden"}');

        $this->expectException(CheckoutRegionPolicyMalformedException::class);
        $this->policy()->all();
    }

    public function test_the_shared_normaliser_refuses_what_the_reader_would(): void
    {
        $this->assertSame(['BG' => RegionMode::HIDDEN], CheckoutRegionPolicy::normalizeCountries([['country' => 'bg', 'mode' => 'hidden']]));

        foreach ([
            [['country' => 'ZZ', 'mode' => 'hidden']],
            [['country' => 'BG', 'mode' => 'maybe']],
            [['country' => 'BG', 'mode' => null]],
            [['country' => null, 'mode' => 'hidden']],
            [['country' => 'BG', 'mode' => 'hidden'], ['country' => 'bg', 'mode' => 'required']],
        ] as $rows) {
            try {
                CheckoutRegionPolicy::normalizeCountries($rows);
                $this->fail('expected a refusal');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- the settings page --------------------------------------------------------------------------------------------------------

    public function test_the_page_shows_what_is_stored(): void
    {
        $this->settings()->set(CheckoutRegionPolicy::DEFAULT_KEY, 'required');
        $this->settings()->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"BG":"hidden"}');
        $this->actingAs($this->staff(), 'staff');

        $component = Livewire::test(CheckoutSettings::class);

        $component->assertFormSet(['default' => 'required']);
        $this->assertSame([['country' => 'BG', 'mode' => 'hidden']], array_values($component->get('data.exceptions')));
    }

    public function test_saving_writes_both_keys(): void
    {
        $this->actingAs($this->staff(), 'staff');

        Livewire::test(CheckoutSettings::class)
            ->fillForm(['default' => 'required', 'exceptions' => [['country' => 'BG', 'mode' => 'hidden'], ['country' => 'US', 'mode' => 'optional']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('required', $this->settings()->get(CheckoutRegionPolicy::DEFAULT_KEY));
        $this->assertSame('{"BG":"hidden","US":"optional"}', $this->settings()->get(CheckoutRegionPolicy::COUNTRIES_KEY));
        $this->assertSame(RegionMode::HIDDEN, $this->policy()->modeFor('BG'), 'what the page wrote is what the reader reads');
    }

    public function test_saving_the_defaults_forgets_the_keys_to_keep_the_store_clean(): void
    {
        $this->settings()->set(CheckoutRegionPolicy::DEFAULT_KEY, 'required');
        $this->settings()->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"BG":"hidden"}');
        $this->actingAs($this->staff(), 'staff');

        Livewire::test(CheckoutSettings::class)->fillForm(['default' => 'optional', 'exceptions' => []])->call('save')->assertHasNoFormErrors();

        $this->assertNull($this->settings()->get(CheckoutRegionPolicy::DEFAULT_KEY));
        $this->assertNull($this->settings()->get(CheckoutRegionPolicy::COUNTRIES_KEY));
    }

    public function test_the_page_refuses_an_unknown_country_a_duplicate_and_an_unknown_mode(): void
    {
        $this->actingAs($this->staff(), 'staff');

        foreach ([
            [['country' => 'ZZ', 'mode' => 'hidden']],
            [['country' => 'BG', 'mode' => 'hidden'], ['country' => 'BG', 'mode' => 'required']],
            [['country' => 'BG', 'mode' => 'maybe']],
        ] as $rows) {
            Livewire::test(CheckoutSettings::class)->fillForm(['default' => 'optional', 'exceptions' => $rows])->call('save')->assertHasFormErrors();
        }

        Livewire::test(CheckoutSettings::class)->fillForm(['default' => 'sometimes', 'exceptions' => []])->call('save')->assertHasFormErrors(['default']);

        $this->assertNull($this->settings()->get(CheckoutRegionPolicy::COUNTRIES_KEY));
        $this->assertNull($this->settings()->get(CheckoutRegionPolicy::DEFAULT_KEY));
    }

    public function test_the_fact_line_names_the_default_and_every_exception(): void
    {
        $this->assertSame('Shown and optional for every country.', CheckoutSettings::factLine('optional', []));
        $this->assertSame(
            'Shown and optional for every country except: Bulgaria — hidden; United States — required.',
            CheckoutSettings::factLine('optional', [['country' => 'BG', 'mode' => 'hidden'], ['country' => 'US', 'mode' => 'required']]),
        );
        $this->assertSame('Hidden for every country.', CheckoutSettings::factLine('hidden', [['country' => 'ZZ', 'mode' => 'required'], ['country' => 'BG', 'mode' => null]]), 'a row that is not a known country with a mode is skipped');
    }

    public function test_only_staff_with_settings_manage_can_open_the_page(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $this->assertTrue(CheckoutSettings::canAccess());

        $role = Role::create('No settings '.Str::uuid(), [Permission::ORDER_MANAGE]);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('nosettings-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'No Settings', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        $this->assertFalse(CheckoutSettings::canAccess());
        Livewire::test(CheckoutSettings::class)->assertForbidden();
    }
}
