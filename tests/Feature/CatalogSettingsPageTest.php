<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\CatalogSettings;
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
 * Exercises the real, production CatalogSettings page — admin-panel-
 * design.md §13.4, Site Settings' first real Catalog-specific
 * consumer. Mirrors LocaleSettingsPageTest's established conventions
 * exactly.
 */
class CatalogSettingsPageTest extends TestCase
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

    public function test_toggling_and_saving_persists_the_setting_through_the_real_repository(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CatalogSettings::class)
            ->fillForm(['product_group_required' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1', app(SiteSettingsRepository::class)->get('catalog.product_group_required'));
    }

    public function test_the_settings_page_defaults_to_off_when_never_set(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CatalogSettings::class)
            ->assertSchemaStateSet(['product_group_required' => false]);
    }

    /**
     * All four field-visibility toggles default TRUE (unlike
     * product_group_required, which defaults false) — an installation
     * that never visits this page must keep every field shown, exactly
     * today's behavior, not a silently narrower one.
     */
    public function test_the_four_field_visibility_toggles_default_to_on_when_never_set(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CatalogSettings::class)
            ->assertSchemaStateSet([
                'season_field_enabled' => true,
                'brand_field_enabled' => true,
                'tags_field_enabled' => true,
                'product_group_field_enabled' => true,
            ]);
    }

    public function test_turning_a_field_visibility_toggle_off_persists_through_the_real_repository(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CatalogSettings::class)
            ->fillForm([
                'season_field_enabled' => false,
                'brand_field_enabled' => false,
                'tags_field_enabled' => false,
                'product_group_field_enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SiteSettingsRepository::class);
        $this->assertSame('0', $settings->get('catalog.season_field_enabled'));
        $this->assertSame('0', $settings->get('catalog.brand_field_enabled'));
        $this->assertSame('0', $settings->get('catalog.tags_field_enabled'));
        $this->assertSame('0', $settings->get('catalog.product_group_field_enabled'));
    }

    /**
     * product_group_required is hidden (->visible() false) whenever
     * product_group_field_enabled is off — a hidden field does not
     * dehydrate, so save() must not blow up and must keep a sane stored
     * value rather than an undefined-index error.
     */
    public function test_saving_with_product_group_disabled_does_not_error_and_stores_required_as_off(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CatalogSettings::class)
            ->fillForm(['product_group_field_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SiteSettingsRepository::class);
        $this->assertSame('0', $settings->get('catalog.product_group_field_enabled'));
        $this->assertSame('0', $settings->get('catalog.product_group_required'));
    }

    public function test_the_real_permission_matrix_for_settings_access(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(CatalogSettings::getUrl())->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(CatalogSettings::getUrl())->assertForbidden();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(CatalogSettings::getUrl())->assertForbidden();
    }
}
