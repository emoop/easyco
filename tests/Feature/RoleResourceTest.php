<?php

namespace Tests\Feature;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\RoleResource\Pages\ViewRole;
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
use ReflectionMethod;
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
     * Supersedes the previous "no clickable row" behavior now that
     * ViewRole exists: every row navigates somewhere again, but a
     * system role's row goes to View (read-only, never 403s) and never
     * to Edit — the Edit action itself stays hidden for it, exactly as
     * canEdit() already dictates.
     */
    public function test_a_system_roles_row_now_navigates_to_view_not_nowhere(): void
    {
        $this->actingAsPanelAdministrator();

        $systemRole = app(RoleRepository::class)->findSystemRoleByName('Administrator');
        $systemRoleModel = RoleModel::find($systemRole->id());

        $component = Livewire::test(ListRoles::class);

        $component->assertTableActionHidden('edit', $systemRoleModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($systemRoleModel);
        $this->assertSame(RoleResource::getUrl('view', ['record' => $systemRoleModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
    }

    /**
     * The inverse — confirms the row's recordUrl (-> view) and the
     * separate, visible EditAction (-> edit) coexist correctly for a
     * non-system role, and neither one 403s.
     */
    public function test_a_custom_roles_row_still_navigates_correctly_and_its_edit_button_remains_separate(): void
    {
        $this->actingAsPanelAdministrator();

        $custom = Role::create('Custom', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($custom);
        $customModel = RoleModel::find($custom->id());

        $component = Livewire::test(ListRoles::class);

        $component->assertTableActionVisible('edit', $customModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($customModel);
        $this->assertSame(RoleResource::getUrl('view', ['record' => $customModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(RoleResource::getUrl('edit', ['record' => $customModel]))->assertOk();
    }

    /**
     * A real regression test for permissionGroups()'s hand-maintained-
     * map tradeoff (RoleResource's own docblock explains why it can't
     * derive itself from the enum): a future 18th Permission added to
     * the enum but forgotten in this map should fail the suite rather
     * than silently vanishing from the View page.
     */
    public function test_permission_groups_account_for_every_real_permission_exactly_once(): void
    {
        $method = new ReflectionMethod(RoleResource::class, 'permissionGroups');
        $method->setAccessible(true);
        $groups = $method->invoke(null);

        $flattened = array_merge(...array_values($groups));

        $this->assertEqualsCanonicalizing(Permission::cases(), $flattened);
        $this->assertCount(count(Permission::cases()), $flattened, 'permissionGroups() must not list any permission more than once.');
    }

    public function test_viewing_a_system_role_shows_its_full_permission_set(): void
    {
        $this->actingAsPanelAdministrator();

        $systemRole = app(RoleRepository::class)->findSystemRoleByName('Administrator');

        $component = Livewire::test(ViewRole::class, ['record' => $systemRole->id()]);

        foreach (Permission::cases() as $permission) {
            $component->assertSchemaComponentStateSet($permission->value, true);
        }
    }

    public function test_viewing_a_custom_role_shows_which_permissions_are_granted_and_which_are_not(): void
    {
        $this->actingAsPanelAdministrator();

        $custom = Role::create('Limited', [Permission::PRODUCT_VIEW, Permission::COST_VIEW]);
        app(RoleRepository::class)->save($custom);

        $component = Livewire::test(ViewRole::class, ['record' => $custom->id()]);

        $component->assertSchemaComponentStateSet(Permission::PRODUCT_VIEW->value, true);
        $component->assertSchemaComponentStateSet(Permission::COST_VIEW->value, true);
        $component->assertSchemaComponentStateSet(Permission::PRODUCT_MANAGE->value, false);
        $component->assertSchemaComponentStateSet(Permission::COST_MANAGE->value, false);
        $component->assertSchemaComponentStateSet(Permission::STAFF_MANAGE->value, false);
    }

    /**
     * Viewing uses viewAnyPermission() directly (viewPermission() ===
     * viewAnyPermission() === Permission::STAFF_MANAGE), the exact same
     * gate the list itself uses — so Administrator (the only shipped
     * role holding STAFF_MANAGE, per §4.1) can view, and Manager/Product
     * Entry are forbidden, identically to
     * test_the_real_permission_matrix_across_all_three_shipped_roles's
     * own index/create assertions. Confirmed explicitly here for the
     * View route specifically, rather than assumed from that test.
     */
    public function test_the_real_permission_matrix_for_viewing(): void
    {
        $this->actingAsPanelAdministrator();

        $systemRole = app(RoleRepository::class)->findSystemRoleByName('Administrator');
        $this->get(RoleResource::getUrl('view', ['record' => $systemRole->id()]))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(RoleResource::getUrl('view', ['record' => $systemRole->id()]))->assertForbidden();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(RoleResource::getUrl('view', ['record' => $systemRole->id()]))->assertForbidden();
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
