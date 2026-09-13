<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Contracts\PasswordHasher as AccountPasswordHasher;
use EasyCo\Staff\Contracts\PasswordHasher as StaffPasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * New territory for this suite: the first Filament/Livewire panel test.
 * No existing precedent to mirror exactly (StaffGuardTest from Part 2
 * calls Auth::guard(...)->attempt() directly, never through a real login
 * form), so this establishes the convention: real login is exercised
 * through Filament's actual Livewire login component
 * (Filament\Auth\Pages\Login), submitting its real 'email'/'password'
 * form fields and calling its real 'authenticate' action — confirmed by
 * reading the installed v5.8.1 source directly, not assumed from memory
 * or an older Filament version's API.
 *
 * This is THE test proving admin-panel-design.md §3's central claim for
 * real: building this panel closes README's/staff-access-domain-design.md
 * §8's own "no staff login/logout HTTP endpoint yet" gap — a real Staff
 * can now actually log in through a browser-equivalent request, not just
 * bootstrap via the artisan command or authenticate directly in a test.
 */
class AdminPanelGuardTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_an_unauthenticated_visitor_is_redirected_to_the_login_page(): void
    {
        $response = $this->get('/admin');

        // Filament\Http\Middleware\Authenticate::redirectTo() returns
        // Filament::getLoginUrl() — confirmed directly against the
        // installed source rather than assumed; a raw 401 (the JSON
        // API's own behavior) would be wrong here, since this is a
        // browser-facing panel, not a JSON endpoint.
        $response->assertRedirect('/admin/login');
    }

    public function test_a_real_staff_login_through_the_panel_succeeds_and_establishes_a_staff_guard_session(): void
    {
        $this->registerStaff('staff@example.com', 'password123');

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'staff@example.com',
                'password' => 'password123',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertTrue(Auth::guard('staff')->check());
    }

    public function test_a_customer_account_cannot_log_into_the_panel(): void
    {
        $this->registerAccount('user@example.com', 'password123');

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'user@example.com',
                'password' => 'password123',
            ])
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertFalse(Auth::guard('staff')->check());
    }
}
