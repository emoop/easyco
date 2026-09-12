<?php

namespace Tests\Feature;

use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Covers staff-access-domain-design.md §11's "Middleware Feature tests"
 * bullets. No real merchant route carries `staff.can` yet (Part 3), so
 * this registers test-only routes directly via the Route facade in
 * setUp() purely to exercise EnsureStaffHasPermission in isolation.
 * These routes are NOT added to routes/api.php.
 */
class EnsureStaffHasPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');

        // Four real permissions, deliberately chosen to distinguish all
        // three shipped roles from each other:
        // - PRODUCT_MANAGE: all three grant it.
        // - COST_VIEW: Administrator + Manager only.
        // - REFUND_BANK: Administrator only.
        // - STAFF_MANAGE: Administrator only, but a DIFFERENT permission
        //   than REFUND_BANK — specifically to prove the check is on the
        //   permission itself, never on "is this staff member the
        //   Administrator" (§5's own "never on the role's name" rule).
        Route::middleware(['auth:staff', 'staff.can:product_manage'])
            ->get('/api/_test/requires-product-manage', fn () => response()->noContent());
        Route::middleware(['auth:staff', 'staff.can:cost_view'])
            ->get('/api/_test/requires-cost-view', fn () => response()->noContent());
        Route::middleware(['auth:staff', 'staff.can:refund_bank'])
            ->get('/api/_test/requires-refund-bank', fn () => response()->noContent());
        Route::middleware(['auth:staff', 'staff.can:staff_manage'])
            ->get('/api/_test/requires-staff-manage', fn () => response()->noContent());
        // Deliberately no permission value — proves §5 rule 1's
        // directly-testable half.
        Route::middleware(['auth:staff', 'staff.can'])
            ->get('/api/_test/misconfigured-no-permission', fn () => response()->noContent());
        Route::middleware(['auth:staff', 'staff.can:not_a_real_permission'])
            ->get('/api/_test/invalid-permission-string', fn () => response()->noContent());
    }

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

    private function actingAsStaff(Staff $staff): self
    {
        $model = StaffModel::find($staff->id());

        return $this->actingAs($model, 'staff');
    }

    public function test_an_unauthenticated_request_is_rejected_with_401(): void
    {
        $response = $this->getJson('/api/_test/requires-product-manage');

        $response->assertStatus(401);
    }

    /** The real matrix, not a spot check — one Staff per shipped role, all four test routes. */
    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $administrator = $this->staffWithRole('Administrator');
        $this->actingAsStaff($administrator);
        $this->getJson('/api/_test/requires-product-manage')->assertStatus(204);
        $this->getJson('/api/_test/requires-cost-view')->assertStatus(204);
        $this->getJson('/api/_test/requires-refund-bank')->assertStatus(204);
        $this->getJson('/api/_test/requires-staff-manage')->assertStatus(204);

        // Fresh instance per role — logout guarantees real isolation
        // rather than masking a bug via leftover session state.
        Auth::guard('staff')->logout();

        $manager = $this->staffWithRole('Manager');
        $this->actingAsStaff($manager);
        $this->getJson('/api/_test/requires-product-manage')->assertStatus(204);
        $this->getJson('/api/_test/requires-cost-view')->assertStatus(204);
        $this->getJson('/api/_test/requires-refund-bank')->assertStatus(403);
        $this->getJson('/api/_test/requires-staff-manage')->assertStatus(403);

        Auth::guard('staff')->logout();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAsStaff($productEntry);
        $this->getJson('/api/_test/requires-product-manage')->assertStatus(204);
        $this->getJson('/api/_test/requires-cost-view')->assertStatus(403);
        $this->getJson('/api/_test/requires-refund-bank')->assertStatus(403);
        $this->getJson('/api/_test/requires-staff-manage')->assertStatus(403);
    }

    /**
     * The single most important behavioral test in this file (§11's own
     * framing, applied here to isActive rather than to the missing-
     * declaration case, which is Part 3's). Flipping is_active directly
     * on the Eloquent model is test-only scaffolding — Staff has no
     * deactivate() mutator by design (Part 1).
     */
    public function test_a_deactivated_staff_member_is_denied_immediately(): void
    {
        $staff = $this->staffWithRole('Product Entry');
        $this->actingAsStaff($staff);

        StaffModel::find($staff->id())->update(['is_active' => false]);

        $response = $this->getJson('/api/_test/requires-product-manage');

        $response->assertStatus(403);
    }

    /**
     * Proves rule 1's directly-testable half: even a staff member who
     * could do anything is denied when the declaration itself is
     * malformed — confirming this isn't a "does this person have enough
     * power" check gone wrong, but a correctly-triggered
     * configuration-error deny.
     */
    public function test_a_route_with_the_middleware_attached_but_no_permission_declared_is_denied(): void
    {
        $administrator = $this->staffWithRole('Administrator');
        $this->actingAsStaff($administrator);

        $response = $this->getJson('/api/_test/misconfigured-no-permission');

        $response->assertStatus(403);
    }

    public function test_a_denied_request_names_the_missing_permission_in_the_response_body(): void
    {
        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAsStaff($productEntry);

        $response = $this->getJson('/api/_test/requires-refund-bank');

        $response->assertStatus(403);
        $this->assertStringContainsString('refund_bank', $response->json('message'));
    }

    public function test_an_invalid_permission_string_is_denied_not_a_500(): void
    {
        $administrator = $this->staffWithRole('Administrator');
        $this->actingAsStaff($administrator);

        $response = $this->getJson('/api/_test/invalid-permission-string');

        $response->assertStatus(403);
    }
}
