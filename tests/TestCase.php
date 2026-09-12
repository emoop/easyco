<?php

namespace Tests;

use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Вариант Б: a single shared helper, rather than hand-writing staff
     * setup in each of the ~19 pre-existing Feature tests that call a
     * merchant endpoint. Applying `staff.can:*` to every merchant route
     * (Part 3 of staff-access-domain-design.md) would otherwise break
     * every one of those tests, which were written before permissions
     * existed and are not testing permission-boundary behavior — that is
     * EnsureStaffHasPermissionTest's job (Part 2), not theirs.
     *
     * Defaults to Administrator specifically so these pre-existing tests
     * keep testing what they already test (the feature itself), rather
     * than incidentally becoming permission tests too.
     */
    protected function actingAsAdministrator(): StaffModel
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            $this->seed(StaffSystemRolesSeeder::class);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create(
            'test-administrator-'.Str::uuid().'@example.com',
            app(PasswordHasher::class)->hash('irrelevant-not-checked'),
            'Test Administrator',
            $role,
        );
        app(StaffRepository::class)->save($staff);

        $model = StaffModel::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }
}
