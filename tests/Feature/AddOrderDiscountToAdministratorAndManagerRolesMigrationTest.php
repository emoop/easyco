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
 * The data migration that extends ALREADY-INSTALLED Administrator and
 * Manager system roles with Permission::ORDER_DISCOUNT
 * (order-editing-design.md E5). Mirrors
 * AddProductDeleteToAdministratorRoleMigrationTest, for two roles instead of
 * one; the fresh-install half is StaffSystemRolesSeederTest's.
 */
class AddOrderDiscountToAdministratorAndManagerRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'packages/EasyCo/Staff/database/migrations/2026_09_30_000001_add_order_discount_to_administrator_and_manager_roles.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    /** The state of a store installed before this change: every system role seeded, none holding order_discount. */
    private function existingInstallWithoutOrderDiscount(): void
    {
        app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));

        foreach (DB::table('staff_roles')->where('is_system', true)->get(['id', 'permissions']) as $role) {
            $kept = array_values(array_diff(json_decode((string) $role->permissions, true), [Permission::ORDER_DISCOUNT->value]));
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

    public function test_the_seeder_gives_a_fresh_install_order_discount_to_administrator_and_manager_only(): void
    {
        app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));

        $this->assertContains('order_discount', $this->rawPermissions('Administrator'));
        $this->assertContains('order_discount', $this->rawPermissions('Manager'));
        $this->assertNotContains('order_discount', $this->rawPermissions('Product Entry'));
    }

    public function test_up_grants_it_to_exactly_administrator_and_manager(): void
    {
        $this->existingInstallWithoutOrderDiscount();
        $productEntryBefore = $this->rawPermissions('Product Entry');
        $managerBefore = $this->rawPermissions('Manager');

        $this->migration()->up();

        $this->assertContains('order_discount', $this->rawPermissions('Administrator'));
        $this->assertContains('order_discount', $this->rawPermissions('Manager'));
        $this->assertSame($productEntryBefore, $this->rawPermissions('Product Entry'), 'no other system role is touched');

        // Appended, never replaced: Manager keeps everything it had.
        $this->assertSame($managerBefore, array_values(array_diff($this->rawPermissions('Manager'), ['order_discount'])));

        self::assertEqualsCanonicalizing(Permission::cases(), app(RoleRepository::class)->findSystemRoleByName('Administrator')->permissions());
        $this->assertContains(Permission::ORDER_DISCOUNT, app(RoleRepository::class)->findSystemRoleByName('Manager')->permissions());
    }

    public function test_up_is_idempotent(): void
    {
        $this->existingInstallWithoutOrderDiscount();

        $this->migration()->up();
        $this->migration()->up();

        foreach (['Administrator', 'Manager'] as $name) {
            $this->assertSame(1, count(array_keys($this->rawPermissions($name), 'order_discount', true)), $name);
        }
    }

    public function test_down_removes_exactly_this_permission_from_both_roles(): void
    {
        $this->existingInstallWithoutOrderDiscount();
        $before = [$this->rawPermissions('Administrator'), $this->rawPermissions('Manager')];
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame($before, [$this->rawPermissions('Administrator'), $this->rawPermissions('Manager')]);
    }

    public function test_down_is_a_no_op_when_the_permission_was_never_there(): void
    {
        $this->existingInstallWithoutOrderDiscount();
        $before = [$this->rawPermissions('Administrator'), $this->rawPermissions('Manager')];

        $this->migration()->down();

        $this->assertSame($before, [$this->rawPermissions('Administrator'), $this->rawPermissions('Manager')]);
    }

    public function test_a_merchant_created_role_with_either_name_is_never_touched(): void
    {
        $this->existingInstallWithoutOrderDiscount();
        $ids = [];

        foreach (['Administrator', 'Manager'] as $name) {
            $ids[] = DB::table('staff_roles')->insertGetId([
                'name' => $name.' (custom)',
                'permissions' => json_encode([Permission::ORDER_VIEW->value]),
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Same name, not system: must not be matched either.
        $sameName = DB::table('staff_roles')->insertGetId([
            'name' => 'Manager',
            'permissions' => json_encode([Permission::ORDER_VIEW->value]),
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        foreach ([...$ids, $sameName] as $id) {
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
