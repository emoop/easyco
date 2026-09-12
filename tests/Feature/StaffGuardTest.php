<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Contracts\PasswordHasher as AccountPasswordHasher;
use EasyCo\Staff\Contracts\PasswordHasher as StaffPasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Covers staff-access-domain-design.md §11's "Guard Feature tests"
 * bullet: a staff login does not authenticate the customer guard, and a
 * customer login does not authenticate staff, asserted directly, both
 * directions. No HTTP login endpoint exists for staff yet (that
 * question is still open with the domain owner) — these tests exercise
 * the guard configuration directly via Auth::guard(...)->attempt(), the
 * same underlying mechanism AccountSessionController uses, just called
 * directly instead of through a controller.
 */
class StaffGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same reason as AccountSessionControllerTest — Sanctum's
        // EnsureFrontendRequestsAreStateful needs a recognized Referer
        // to engage the session pipeline at all.
        $this->withHeader('Referer', 'http://localhost/');

        // A REAL, DISCOVERED GOTCHA of calling Auth::guard(...)->attempt()
        // directly instead of through a controller: SessionGuard::login()
        // fires Illuminate\Auth\Events\Login, and this project's own
        // pre-existing App\Listeners\MergeGuestCartIntoAccountCart
        // listens for it on the 'customer' guard and calls
        // $request->session(). Outside a real HTTP request/middleware
        // cycle, the shared Request instance never has a session store
        // attached (Illuminate\Session\Middleware\StartSession normally
        // does this), so that listener throws "Session store not set on
        // request." This is unrelated to Staff/the new guard — it is a
        // property of testing ANY 'customer'-guard login without going
        // through the HTTP kernel. Attaching a real session store here,
        // exactly like StartSession middleware does, fixes it without
        // touching that listener or any production code.
        $this->startSession();
        $this->app['request']->setLaravelSession($this->app['session.store']);
    }

    private function registerStaff(string $email = 'staff@example.com', string $password = 'password123', ?Role $role = null): Staff
    {
        if ($role === null) {
            $role = app(RoleRepository::class)->findSystemRoleByName('Product Entry');

            if ($role === null) {
                app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));
                $role = app(RoleRepository::class)->findSystemRoleByName('Product Entry');
            }
        }

        $staff = Staff::create($email, app(StaffPasswordHasher::class)->hash($password), 'Test Staff', $role);
        app(StaffRepository::class)->save($staff);

        return $staff;
    }

    private function registerAccount(string $email = 'user@example.com', string $password = 'password123'): void
    {
        $account = Account::register($email, app(AccountPasswordHasher::class)->hash($password));
        app(AccountRepository::class)->save($account);
    }

    public function test_a_staff_login_does_not_authenticate_the_customer_guard(): void
    {
        $this->registerStaff('staff@example.com', 'password123');

        $attempted = Auth::guard('staff')->attempt(['email' => 'staff@example.com', 'password' => 'password123']);

        $this->assertTrue($attempted);
        $this->assertTrue(Auth::guard('staff')->check());
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_customer_login_does_not_authenticate_the_staff_guard(): void
    {
        $this->registerAccount('user@example.com', 'password123');

        $attempted = Auth::guard('customer')->attempt(['email' => 'user@example.com', 'password' => 'password123']);

        $this->assertTrue($attempted);
        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertFalse(Auth::guard('staff')->check());
    }

    public function test_staff_guard_rejects_wrong_password(): void
    {
        $this->registerStaff('staff@example.com', 'password123');

        $attempted = Auth::guard('staff')->attempt(['email' => 'staff@example.com', 'password' => 'the-wrong-password']);

        $this->assertFalse($attempted);
        $this->assertFalse(Auth::guard('staff')->check());
    }

    /**
     * VERIFIED FOR REAL, not assumed: Laravel's EloquentUserProvider
     * (vendor/laravel/framework/src/Illuminate/Auth/EloquentUserProvider.php)
     * has no built-in "active" concept whatsoever —
     * retrieveByCredentials() matches purely on the given credential
     * columns (email here, password filtered out) and
     * validateCredentials() only checks the hashed password. Guard-level
     * authentication and permission-checking are deliberately separate
     * concerns in this design: attempt() succeeds here regardless of
     * is_active. EnsureStaffHasPermissionTest's own
     * test_a_deactivated_staff_member_is_denied_immediately is what
     * actually proves deactivation is enforced — on every request, via
     * the permission middleware, not the guard.
     *
     * Flipping is_active directly on the Eloquent model is test-only
     * scaffolding, not exercising a real feature: Staff has no
     * deactivate() mutator by design (Part 1) — no consumer needs one
     * until the future admin UI.
     */
    public function test_the_guard_itself_authenticates_a_deactivated_staff_member_regardless_of_is_active(): void
    {
        $staff = $this->registerStaff('staff@example.com', 'password123');
        StaffModel::find($staff->id())->update(['is_active' => false]);

        $attempted = Auth::guard('staff')->attempt(['email' => 'staff@example.com', 'password' => 'password123']);

        $this->assertTrue($attempted);
        $this->assertTrue(Auth::guard('staff')->check());
    }
}
