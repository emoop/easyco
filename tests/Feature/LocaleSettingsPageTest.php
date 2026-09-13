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
}
