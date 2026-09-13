<?php

namespace Tests\Feature;

use App\Filament\Resources\StaffResource;
use App\Filament\Resources\StaffResource\Pages\CreateStaff;
use App\Filament\Resources\StaffResource\Pages\EditStaff;
use App\Filament\StaffPanelUser;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production StaffResource — not a test-only
 * fixture. Mirrors the established Livewire-testing convention from
 * Part 1.
 *
 * REAL, DISCOVERED GOTCHA — same one RoleResourceTest documents: tests
 * hitting a real panel route via $this->get(...) must actingAs() a
 * StaffPanelUser, not a plain StaffModel, or Filament\Http\Middleware\
 * Authenticate's canAccessPanel() check 403s regardless of the real
 * permission outcome. This project's own base TestCase::
 * actingAsAdministrator() returns a plain StaffModel (correct for its
 * ~19 JSON-API-route consumers) — deliberately NOT reused here.
 */
class StaffResourceTest extends TestCase
{
    use RefreshDatabase;

    private function persistedRole(string $name = 'Manager', array $permissions = [Permission::PRODUCT_VIEW]): Role
    {
        $role = Role::create($name, $permissions);
        app(RoleRepository::class)->save($role);

        return $role;
    }

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

    public function test_creating_a_staff_hashes_the_password_through_the_real_hasher(): void
    {
        $this->actingAsPanelAdministrator();
        $role = $this->persistedRole();

        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'Petar Petrov',
                'email' => 'petar@example.com',
                'password' => 'a-strong-password',
                'role_id' => $role->id(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $staff = app(StaffRepository::class)->findByEmail('petar@example.com');

        $this->assertNotNull($staff);
        $this->assertTrue(app(PasswordHasher::class)->verify('a-strong-password', $staff->passwordHash()));
    }

    public function test_creating_a_staff_always_starts_active(): void
    {
        $this->actingAsPanelAdministrator();
        $role = $this->persistedRole();

        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'Petar Petrov',
                'email' => 'petar@example.com',
                'password' => 'a-strong-password',
                'role_id' => $role->id(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $staff = app(StaffRepository::class)->findByEmail('petar@example.com');

        $this->assertTrue($staff->isActive());
    }

    public function test_editing_a_staff_can_reassign_their_role(): void
    {
        $this->actingAsPanelAdministrator();

        $originalRole = $this->persistedRole('Manager', [Permission::PRODUCT_VIEW]);
        $staff = Staff::create('petar@example.com', app(PasswordHasher::class)->hash('password123'), 'Petar', $originalRole);
        app(StaffRepository::class)->save($staff);

        $newRole = $this->persistedRole('Product Entry', [Permission::PRODUCT_VIEW, Permission::PRODUCT_MANAGE]);

        Livewire::test(EditStaff::class, ['record' => $staff->id()])
            ->fillForm([
                'role_id' => $newRole->id(),
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(StaffRepository::class)->findById($staff->id());

        $this->assertSame($newRole->id(), $reloaded->role()->id());
    }

    public function test_editing_a_staff_can_deactivate_and_reactivate(): void
    {
        // The acting Administrator is a second, real, active Staff row —
        // deactivating $staff below never risks tripping the
        // last-active-staff guard.
        $this->actingAsPanelAdministrator();

        $role = $this->persistedRole();
        $staff = Staff::create('petar@example.com', app(PasswordHasher::class)->hash('password123'), 'Petar', $role);
        app(StaffRepository::class)->save($staff);

        Livewire::test(EditStaff::class, ['record' => $staff->id()])
            ->fillForm(['is_active' => false, 'role_id' => $role->id()])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(StaffRepository::class)->findById($staff->id());
        $this->assertFalse($reloaded->isActive());

        Livewire::test(EditStaff::class, ['record' => $staff->id()])
            ->fillForm(['is_active' => true, 'role_id' => $role->id()])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(StaffRepository::class)->findById($staff->id());
        $this->assertTrue($reloaded->isActive());
    }

    /**
     * The most important test in this file — proves
     * EditStaff::handleRecordUpdate()'s last-active-staff guard actually
     * blocks the update, keeps the record active, and surfaces a real
     * danger notification, rather than silently locking every human out
     * of the panel.
     */
    public function test_deactivating_the_last_active_staff_member_is_blocked(): void
    {
        $admin = $this->actingAsPanelAdministrator();
        $adminRoleId = (string) $admin->role_id;

        Livewire::test(EditStaff::class, ['record' => $admin->id])
            ->fillForm(['is_active' => false, 'role_id' => $adminRoleId])
            ->call('save')
            ->assertNotified();

        $reloaded = app(StaffRepository::class)->findById((string) $admin->id);
        $this->assertTrue($reloaded->isActive());
    }

    /** Confirms the guard is specifically about the LAST one, not deactivation in general. */
    public function test_deactivating_one_of_several_active_staff_succeeds(): void
    {
        $this->actingAsPanelAdministrator();

        $role = $this->persistedRole();
        $staff = Staff::create('petar@example.com', app(PasswordHasher::class)->hash('password123'), 'Petar', $role);
        app(StaffRepository::class)->save($staff);

        Livewire::test(EditStaff::class, ['record' => $staff->id()])
            ->fillForm(['is_active' => false, 'role_id' => $role->id()])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(StaffRepository::class)->findById($staff->id());
        $this->assertFalse($reloaded->isActive());
    }

    public function test_leaving_the_password_field_blank_on_edit_keeps_the_existing_hash(): void
    {
        $this->actingAsPanelAdministrator();

        $role = $this->persistedRole();
        $originalHash = app(PasswordHasher::class)->hash('original-password');
        $staff = Staff::create('petar@example.com', $originalHash, 'Petar', $role);
        app(StaffRepository::class)->save($staff);

        Livewire::test(EditStaff::class, ['record' => $staff->id()])
            ->fillForm(['password' => '', 'role_id' => $role->id(), 'is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(StaffRepository::class)->findById($staff->id());
        $this->assertSame($originalHash, $reloaded->passwordHash());
    }

    public function test_name_and_email_fields_are_disabled_on_the_edit_form(): void
    {
        $this->actingAsPanelAdministrator();

        $role = $this->persistedRole();
        $staff = Staff::create('petar@example.com', app(PasswordHasher::class)->hash('password123'), 'Petar', $role);
        app(StaffRepository::class)->save($staff);

        Livewire::test(EditStaff::class, ['record' => $staff->id()])
            ->assertFormFieldDisabled('name')
            ->assertFormFieldDisabled('email');
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(StaffResource::getUrl('index'))->assertOk();
        $this->get(StaffResource::getUrl('create'))->assertOk();

        // See RoleResourceTest's identical comment: the panel's
        // AuthenticateSession middleware stores the acting user's
        // password hash under 'password_hash_staff' and logs out
        // (redirecting instead of reaching this Resource's own 403) on
        // a mismatch — real when switching actingAs() to a different
        // user mid-test, as this test deliberately does.
        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(StaffResource::getUrl('index'))->assertForbidden();
        $this->get(StaffResource::getUrl('create'))->assertForbidden();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(StaffResource::getUrl('index'))->assertForbidden();
        $this->get(StaffResource::getUrl('create'))->assertForbidden();
    }
}
