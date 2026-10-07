<?php

namespace Tests\Feature;

use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data migration that extends ALREADY-INSTALLED Administrator and Manager
 * system roles with Permission::SHIPPING_MANAGE (shipping-domain-design.md §12.6,
 * stage 5a). Mirrors AddOrderDiscountToAdministratorAndManagerRolesMigrationTest,
 * for the same two roles; the fresh-install half is StaffSystemRolesSeederTest's.
 * The migration is run directly: it has already run on the test database when a
 * test starts.
 */
class AddShippingManageToAdministratorAndManagerRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'packages/EasyCo/Staff/database/migrations/2026_10_10_000001_add_shipping_manage_to_administrator_and_manager_roles.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    /** The state of a store installed before this change: every system role seeded, none holding shipping_manage. */
    private function existingInstallWithoutShippingManage(): void
    {
        app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));

        foreach (DB::table('staff_roles')->where('is_system', true)->get(['id', 'permissions']) as $role) {
            $kept = array_values(array_diff(json_decode((string) $role->permissions, true), [Permission::SHIPPING_MANAGE->value]));
            DB::table('staff_roles')->where('id', $role->id)->update(['permissions' => json_encode($kept)]);
        }
    }

    /** @return string[] */
    private function rawPermissions(string $roleName, bool $isSystem = true): array
    {
        return json_decode(
            (string) DB::table('staff_roles')->where('name', $roleName)->where('is_system', $isSystem)->value('permissions'),
            true
        ) ?? [];
    }

    public function test_the_seeder_gives_a_fresh_install_shipping_manage_to_administrator_and_manager_only(): void
    {
        app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));

        $this->assertContains('shipping_manage', $this->rawPermissions('Administrator'));
        $this->assertContains('shipping_manage', $this->rawPermissions('Manager'));
        $this->assertNotContains('shipping_manage', $this->rawPermissions('Product Entry'));
    }

    public function test_up_grants_it_to_exactly_administrator_and_manager(): void
    {
        $this->existingInstallWithoutShippingManage();
        $productEntryBefore = $this->rawPermissions('Product Entry');
        $managerBefore = $this->rawPermissions('Manager');

        $this->migration()->up();

        $this->assertContains('shipping_manage', $this->rawPermissions('Administrator'));
        $this->assertContains('shipping_manage', $this->rawPermissions('Manager'));
        $this->assertSame($productEntryBefore, $this->rawPermissions('Product Entry'), 'no other system role is touched');

        // Appended, never replaced: Manager keeps everything it had.
        $this->assertSame($managerBefore, array_values(array_diff($this->rawPermissions('Manager'), ['shipping_manage'])));

        self::assertEqualsCanonicalizing(Permission::cases(), app(RoleRepository::class)->findSystemRoleByName('Administrator')->permissions());
        $this->assertContains(Permission::SHIPPING_MANAGE, app(RoleRepository::class)->findSystemRoleByName('Manager')->permissions());
    }

    public function test_up_is_idempotent(): void
    {
        $this->existingInstallWithoutShippingManage();

        $this->migration()->up();
        $this->migration()->up();

        foreach (['Administrator', 'Manager'] as $name) {
            $this->assertSame(1, count(array_keys($this->rawPermissions($name), 'shipping_manage', true)), $name);
        }
    }

    public function test_down_removes_exactly_this_permission_from_both_roles(): void
    {
        $this->existingInstallWithoutShippingManage();
        $before = [$this->rawPermissions('Administrator'), $this->rawPermissions('Manager')];
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame($before, [$this->rawPermissions('Administrator'), $this->rawPermissions('Manager')]);
    }

    public function test_down_is_a_no_op_when_the_permission_was_never_there(): void
    {
        $this->existingInstallWithoutShippingManage();
        $before = [$this->rawPermissions('Administrator'), $this->rawPermissions('Manager')];

        $this->migration()->down();

        $this->assertSame($before, [$this->rawPermissions('Administrator'), $this->rawPermissions('Manager')]);
    }

    public function test_a_merchant_created_role_with_either_name_is_never_touched(): void
    {
        $this->existingInstallWithoutShippingManage();
        $ids = [];

        // Same name, not system: must not be matched either.
        foreach (['Administrator', 'Manager'] as $name) {
            $ids[] = DB::table('staff_roles')->insertGetId([
                'name' => $name,
                'permissions' => json_encode([Permission::ORDER_VIEW->value]),
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->migration()->up();

        foreach ($ids as $id) {
            $this->assertSame([Permission::ORDER_VIEW->value], json_decode((string) DB::table('staff_roles')->where('id', $id)->value('permissions'), true));
        }
    }

    public function test_up_and_down_are_no_ops_without_either_role(): void
    {
        DB::table('staff_roles')->delete();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(0, DB::table('staff_roles')->count());
    }
}
