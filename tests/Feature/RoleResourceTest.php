<?php

namespace Tests\Feature;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\StaffPanelUser;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\RoleModel;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production RoleResource — not a test-only
 * fixture (Part 1's AuthorizesViaStaffPermissionTest used a fixture
 * because no real Resource existed yet). Mirrors the established
 * Livewire-testing convention from Part 1.
 *
 * REAL, DISCOVERED GOTCHA: tests that hit a real panel route via
 * $this->get(...) — as opposed to Livewire::test(...), which mounts a
 * component in-process and never runs Filament\Http\Middleware\
 * Authenticate at all — must actingAs() a StaffPanelUser instance, not
 * a plain StaffModel. That middleware's canAccessPanel() check requires
 * $user instanceof FilamentUser; a bare StaffModel fails that check and
 * gets a blanket 403 regardless of the real permission outcome, masking
 * what this test is actually trying to prove. This project's own base
 * TestCase::actingAsAdministrator() returns a plain StaffModel (correct
 * for its ~19 JSON-API-route consumers, which never touch this
 * middleware) — deliberately NOT reused here for that reason.
 */
class RoleResourceTest extends TestCase
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

    public function test_creating_a_role_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Warehouse',
                'permissions' => [Permission::PRODUCT_VIEW->value, Permission::PRODUCT_MANAGE->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $roleModel = RoleModel::where('name', 'Warehouse')->first();
        $this->assertNotNull($roleModel);

        $role = app(RoleRepository::class)->findById((string) $roleModel->id);

        $this->assertNotNull($role);
        $this->assertSame('Warehouse', $role->name());
        $this->assertFalse($role->isSystem());
        $this->assertEqualsCanonicalizing([Permission::PRODUCT_VIEW, Permission::PRODUCT_MANAGE], $role->permissions());
    }

    public function test_creating_a_role_with_zero_permissions_selected_succeeds(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Empty Role',
                'permissions' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $roleModel = RoleModel::where('name', 'Empty Role')->first();
        $role = app(RoleRepository::class)->findById((string) $roleModel->id);

        $this->assertNotNull($role);
        $this->assertSame([], $role->permissions());
    }

    public function test_editing_a_non_system_role_updates_name_and_permissions(): void
    {
        $this->actingAsPanelAdministrator();

        $custom = Role::create('Custom', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($custom);

        Livewire::test(EditRole::class, ['record' => $custom->id()])
            ->fillForm([
                'name' => 'Renamed',
                'permissions' => [Permission::COST_VIEW->value],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(RoleRepository::class)->findById($custom->id());

        $this->assertSame('Renamed', $reloaded->name());
        $this->assertEqualsCanonicalizing([Permission::COST_VIEW], $reloaded->permissions());
    }

    public function test_a_system_role_cannot_be_edited(): void
    {
        $this->actingAsPanelAdministrator();

        $systemRole = app(RoleRepository::class)->findSystemRoleByName('Administrator');

        $response = $this->get(RoleResource::getUrl('edit', ['record' => $systemRole->id()]));

        $response->assertForbidden();
    }

    /**
     * The real bug this pair of tests closes: Filament's own default
     * row-click URL (built in ListRecords::makeTable(), confirmed
     * directly against the installed v5.8.1 source) resolved the
     * EditAction's own getUrl() before ever consulting canEdit()
     * directly — it only skipped the action if it was isHidden(). An
     * EditAction with no ->visible() override is never hidden, so a
     * system role's entire row (including the "Type" is_system icon
     * column, which has no columnUrl of its own and falls back to the
     * same recordUrl) stayed clickable straight into a 403, even though
     * RoleResource::canEdit() itself already correctly returned false.
     */
    public function test_a_system_role_has_no_clickable_row_or_edit_action_in_the_table(): void
    {
        $this->actingAsPanelAdministrator();

        $systemRole = app(RoleRepository::class)->findSystemRoleByName('Administrator');
        $systemRoleModel = RoleModel::find($systemRole->id());

        $component = Livewire::test(ListRoles::class);

        $component->assertTableActionHidden('edit', $systemRoleModel);

        $this->assertNull($component->instance()->getTable()->getRecordUrl($systemRoleModel));
    }

    /** The inverse — confirms the fix above didn't accidentally break editing for non-system roles. */
    public function test_a_custom_role_still_has_a_clickable_edit_action(): void
    {
        $this->actingAsPanelAdministrator();

        $custom = Role::create('Custom', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($custom);
        $customModel = RoleModel::find($custom->id());

        $component = Livewire::test(ListRoles::class);

        $component->assertTableActionVisible('edit', $customModel);

        $this->assertNotNull($component->instance()->getTable()->getRecordUrl($customModel));
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(RoleResource::getUrl('index'))->assertOk();
        $this->get(RoleResource::getUrl('create'))->assertOk();

        // A REAL, DISCOVERED GOTCHA: the panel's AuthenticateSession
        // middleware stores the acting user's password hash in the
        // session as 'password_hash_staff' on the first request, then
        // compares every subsequent request's authenticated user's hash
        // against it — logging out (redirecting to login) on a mismatch.
        // Switching actingAs() to a genuinely different user mid-test,
        // as this test deliberately does, leaves the PREVIOUS user's
        // hash sitting in session, so the very next request 302s to
        // login instead of reaching this Resource's own 403 at all.
        // Forgetting the stale key re-arms the middleware to store the
        // new user's hash fresh, exactly as it would on their first
        // real request.
        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(RoleResource::getUrl('index'))->assertForbidden();
        $this->get(RoleResource::getUrl('create'))->assertForbidden();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(RoleResource::getUrl('index'))->assertForbidden();
        $this->get(RoleResource::getUrl('create'))->assertForbidden();
    }
}
