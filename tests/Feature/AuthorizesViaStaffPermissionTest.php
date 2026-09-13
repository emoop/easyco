<?php

namespace Tests\Feature;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * No real Resource exists yet (Part 1 is scaffold-only, per
 * admin-panel-design.md §10), so this registers two minimal, test-only
 * Filament Resource classes purely to exercise
 * App\Filament\Concerns\AuthorizesViaStaffPermission in isolation —
 * mirrors the spirit of EnsureStaffHasPermissionTest's own ad-hoc
 * test-only routes (Part 2 of staff-access-domain-design.md), applied to
 * a Resource instead of a route. Neither class is registered with any
 * real Filament panel; only their static canViewAny()/canCreate() are
 * called directly, which is all the trait itself needs to run.
 */
class AuthorizesViaStaffPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): Staff
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

        return $staff;
    }

    private function actingAsStaff(Staff $staff): void
    {
        $model = StaffModel::find($staff->id());

        $this->actingAs($model, 'staff');
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $administrator = $this->staffWithRole('Administrator');
        $this->actingAsStaff($administrator);
        $this->assertTrue(TestOnlyPermissionResource::canViewAny());
        $this->assertTrue(TestOnlyPermissionResource::canCreate());

        Auth::guard('staff')->logout();

        $manager = $this->staffWithRole('Manager');
        $this->actingAsStaff($manager);
        $this->assertTrue(TestOnlyPermissionResource::canViewAny());
        $this->assertFalse(TestOnlyPermissionResource::canCreate());

        Auth::guard('staff')->logout();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAsStaff($productEntry);
        $this->assertTrue(TestOnlyPermissionResource::canViewAny());
        $this->assertFalse(TestOnlyPermissionResource::canCreate());
    }

    public function test_a_resource_with_no_declared_permission_denies_everyone(): void
    {
        $administrator = $this->staffWithRole('Administrator');
        $this->actingAsStaff($administrator);

        $this->assertFalse(TestOnlyNoPermissionResource::canViewAny());
        $this->assertFalse(TestOnlyNoPermissionResource::canCreate());
    }

    public function test_a_deactivated_staff_member_is_denied_by_the_authorization_trait(): void
    {
        $staff = $this->staffWithRole('Administrator');
        $this->actingAsStaff($staff);

        $this->assertTrue(TestOnlyPermissionResource::canViewAny());

        StaffModel::find($staff->id())->update(['is_active' => false]);

        $this->assertFalse(TestOnlyPermissionResource::canViewAny());
    }
}

/** Test-only fixture — never registered with a real Filament panel. */
class TestOnlyPermissionResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::STAFF_MANAGE;
    }
}

/** Test-only fixture — declares no permissions at all, proving the fail-closed default. */
class TestOnlyNoPermissionResource extends Resource
{
    use AuthorizesViaStaffPermission;
}
