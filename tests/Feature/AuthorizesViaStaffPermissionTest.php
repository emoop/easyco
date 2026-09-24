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

    /**
     * UPDATED — AuthenticatedStaffResolver now memoizes the resolved
     * Staff per id for the rest of the request (see its own docblock
     * for the full "reload on every check" finding this fixes): a
     * deactivation is picked up on the NEXT request, not mid-request,
     * an intentional, accepted tradeoff (the same one already governing
     * every other scoped() binding in this codebase). This still proves
     * the real, load-bearing guarantee — "denied without waiting for
     * the session to expire" (EnsureStaffHasPermission's own rule 3) —
     * by simulating that next request via forgetScopedInstances(),
     * which is the actual mechanism a real new request gets for free
     * from a fresh container.
     */
    public function test_a_deactivated_staff_member_is_denied_on_the_next_request(): void
    {
        $staff = $this->staffWithRole('Administrator');
        $this->actingAsStaff($staff);

        $this->assertTrue(TestOnlyPermissionResource::canViewAny());

        StaffModel::find($staff->id())->update(['is_active' => false]);

        // Mid-request: the already-memoized Staff is still what's
        // used — deliberately, not a bug (see this test's own
        // docblock).
        $this->assertTrue(TestOnlyPermissionResource::canViewAny());

        $this->app->forgetScopedInstances();

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
