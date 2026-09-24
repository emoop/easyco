<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\LocaleSettings;
use App\Filament\StaffPanelUser;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production LocaleSettings page — Permission::
 * SETTINGS_MANAGE's first real consumer. Mirrors the established
 * Livewire-testing convention (Part 1) and the StaffPanelUser/
 * password_hash_staff gotchas already documented in
 * RoleResourceTest/StaffResourceTest.
 */
class LocaleSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $model = $this->staffWithRole('Administrator');
        $this->actingAs($model, 'staff');

        return $model;
    }

    public function test_changing_the_locale_through_the_settings_page_persists_it(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('en', app(SiteSettingsRepository::class)->get('site.locale'));
    }

    public function test_the_settings_page_defaults_to_bulgarian_when_never_set(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet(['locale' => 'bg']);
    }

    public function test_the_real_permission_matrix_for_settings_access(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(LocaleSettings::getUrl())->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(LocaleSettings::getUrl())->assertForbidden();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(LocaleSettings::getUrl())->assertForbidden();
    }

    public function test_the_activity_log_tab_defaults_to_off_with_a_twelve_month_retention(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet([
                'activity_log_enabled' => false,
                'activity_log_retention_months' => 12,
            ]);
    }

    public function test_enabling_the_activity_log_and_changing_retention_persists_both_settings(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm([
                'activity_log_enabled' => true,
                'activity_log_retention_months' => 18,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SiteSettingsRepository::class);
        $this->assertSame('1', $settings->get('admin.activity_log_enabled'));
        $this->assertSame('18', $settings->get('admin.activity_log_retention_months'));
    }

    /**
     * A real, confirmed Filament behavior this task's own implementation
     * had to work around: a component hidden by ->visible() is not
     * dehydrated into getState() at all — so saving while the toggle is
     * off (the retention Select hidden) must not blow up, and must fall
     * back to a sane retention value rather than persisting nothing.
     */
    public function test_saving_with_the_activity_log_toggle_off_does_not_error_and_keeps_a_real_retention_value(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SiteSettingsRepository::class);
        $this->assertSame('0', $settings->get('admin.activity_log_enabled'));
        $this->assertSame('12', $settings->get('admin.activity_log_retention_months'));
    }

    /**
     * 'suffix_space' — the exact, byte-for-byte behavior
     * PriceDisplayFormatter had before this setting existed
     * ("{amount} {symbol}"). An installation that never visits the
     * Currency tab must render identically to before.
     */
    public function test_the_currency_tab_defaults_to_suffix_with_a_space_when_never_set(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet(['currency_symbol_position' => 'suffix_space']);
    }

    public function test_changing_the_currency_symbol_position_persists_it(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['currency_symbol_position' => 'prefix'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('prefix', app(SiteSettingsRepository::class)->get('site.currency_symbol_position'));
    }
}
