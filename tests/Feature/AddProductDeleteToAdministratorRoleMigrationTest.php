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
 * The data migration that extends an ALREADY-INSTALLED Administrator role
 * with Permission::PRODUCT_DELETE — catalog-domain-design.md §3.19.9,
 * staff-access-domain-design.md §3.1/§4.1.
 *
 * WHY THIS FILE EXISTS AT ALL: StaffSystemRolesSeeder::seedIfMissing()
 * only CREATES a role that does not exist, so a store installed before this
 * change keeps an Administrator row without `product_delete` forever.
 * StaffSystemRolesSeederTest covers the fresh-install half (the seed is
 * asserted to contain every Permission case); this file covers the upgrade
 * half, including the ways it must NOT behave.
 *
 * THE MIGRATION IS RUN DIRECTLY, not via `artisan migrate`: it has already
 * run against the test database by the time a test starts (RefreshDatabase
 * migrates fresh), so what is exercised here is the migration's own
 * up()/down() against a role set that deliberately looks un-upgraded.
 */
class AddProductDeleteToAdministratorRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'packages/EasyCo/Staff/database/migrations/2026_09_25_000001_add_product_delete_to_administrator_role.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    /**
     * The exact state a store installed before this change is in: three
     * real system roles, with Administrator holding every permission EXCEPT
     * the one this migration adds.
     */
    private function existingInstallWithoutProductDelete(): void
    {
        app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));

        $withoutProductDelete = array_values(array_filter(
            array_map(static fn (Permission $permission): string => $permission->value, Permission::cases()),
            static fn (string $value): bool => $value !== Permission::PRODUCT_DELETE->value,
        ));

        DB::table('staff_roles')
            ->where('name', 'Administrator')
            ->where('is_system', true)
            ->update(['permissions' => json_encode($withoutProductDelete)]);
    }

    /** @return string[] */
    private function rawPermissions(string $roleName, bool $isSystem = true): array
    {
        return json_decode(
            (string) DB::table('staff_roles')
                ->where('name', $roleName)
                ->where('is_system', $isSystem)
                ->value('permissions'),
            true
        ) ?? [];
    }

    public function test_up_adds_product_delete_to_an_existing_administrator_role(): void
    {
        $this->existingInstallWithoutProductDelete();

        $this->assertNotContains(Permission::PRODUCT_DELETE->value, $this->rawPermissions('Administrator'));

        $this->migration()->up();

        $this->assertContains(Permission::PRODUCT_DELETE->value, $this->rawPermissions('Administrator'));

        // Administrator must keep EVERY permission — the migration appends,
        // it never replaces. Read through the domain/repository rather than
        // only the raw column, so an invalid value would fail here too.
        $role = app(RoleRepository::class)->findSystemRoleByName('Administrator');

        $this->assertTrue($role->isSystem());
        self::assertEqualsCanonicalizing(Permission::cases(), $role->permissions());
    }

    public function test_up_touches_no_other_role(): void
    {
        $this->existingInstallWithoutProductDelete();

        $managerBefore = $this->rawPermissions('Manager');
        $productEntryBefore = $this->rawPermissions('Product Entry');

        $this->migration()->up();

        $this->assertSame($managerBefore, $this->rawPermissions('Manager'));
        $this->assertSame($productEntryBefore, $this->rawPermissions('Product Entry'));
    }

    public function test_up_is_idempotent(): void
    {
        $this->existingInstallWithoutProductDelete();

        $this->migration()->up();
        $this->migration()->up();

        $permissions = $this->rawPermissions('Administrator');

        $this->assertSame(1, count(array_keys($permissions, Permission::PRODUCT_DELETE->value, true)));
        $this->assertSame(count(Permission::cases()), count($permissions));
    }

    public function test_down_removes_exactly_this_permission_and_keeps_every_other_one(): void
    {
        $this->existingInstallWithoutProductDelete();
        $this->migration()->up();

        $this->migration()->down();

        $permissions = $this->rawPermissions('Administrator');

        $this->assertNotContains(Permission::PRODUCT_DELETE->value, $permissions);
        $this->assertContains(Permission::PRODUCT_MANAGE->value, $permissions);
        $this->assertContains(Permission::PRODUCT_VIEW->value, $permissions);
        $this->assertSame(count(Permission::cases()) - 1, count($permissions));
    }

    public function test_down_is_a_no_op_when_the_permission_was_never_there(): void
    {
        $this->existingInstallWithoutProductDelete();

        $this->migration()->down();

        $this->assertNotContains(Permission::PRODUCT_DELETE->value, $this->rawPermissions('Administrator'));
        $this->assertSame(count(Permission::cases()) - 1, count($this->rawPermissions('Administrator')));
    }

    /**
     * The narrowing that makes writing a system role's json column directly
     * safe: `is_system = true` is part of the WHERE, so a merchant's own
     * custom role that happens to be called "Administrator" is untouched.
     */
    public function test_a_merchant_created_role_named_administrator_is_never_touched(): void
    {
        $this->existingInstallWithoutProductDelete();

        $customId = DB::table('staff_roles')->insertGetId([
            'name' => 'Administrator',
            'permissions' => json_encode([Permission::PRODUCT_VIEW->value]),
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame(
            [Permission::PRODUCT_VIEW->value],
            json_decode((string) DB::table('staff_roles')->where('id', $customId)->value('permissions'), true)
        );
        $this->assertContains(Permission::PRODUCT_DELETE->value, $this->rawPermissions('Administrator'));
    }

    /** A store with nothing installed yet: a no-op, not an error — the seeder creates the role complete. */
    public function test_up_and_down_are_no_ops_without_an_administrator_role(): void
    {
        DB::table('staff_roles')->delete();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(0, DB::table('staff_roles')->count());
    }
}
