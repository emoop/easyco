<?php

namespace Tests\Feature\Mail;

use App\Filament\StaffPanelUser;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;

/** Staff fixtures for the Mail settings page tests (the same shape as BankTransferSettingsPageTest). */
trait ActsAsMailStaff
{
    private function staffWithRole(string $roleName, ?string $email = null): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $email ??= strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actAsPanelAdministrator(?string $email = null): StaffPanelUser
    {
        $user = $this->staffWithRole('Administrator', $email);
        $this->actingAs($user, 'staff');

        return $user;
    }

    private function stored(string $key): ?string
    {
        return app(SiteSettingsRepository::class)->get($key);
    }

    private function storeSetting(string $key, string $value): void
    {
        app(SiteSettingsRepository::class)->set($key, $value);
    }
}
