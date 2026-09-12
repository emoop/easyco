<?php

namespace Tests\Feature;

use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers StaffSystemRolesSeeder (staff-access-domain-design.md §4.1):
 * all three reserved system Roles get created with the documented
 * permission sets, re-running the seeder is a no-op, and DatabaseSeeder
 * actually wires it in. Mirrors PricingSystemListsSeederTest's exact
 * shape, targeting `staff_roles` instead of `pricing_price_lists`.
 */
class StaffSystemRolesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_the_administrator_role_with_every_permission(): void
    {
        $this->seed(StaffSystemRolesSeeder::class);

        $role = app(RoleRepository::class)->findSystemRoleByName('Administrator');

        self::assertNotNull($role);
        self::assertTrue($role->isSystem());
        self::assertEqualsCanonicalizing(Permission::cases(), $role->permissions());
    }

    public function test_seeding_creates_the_manager_role_with_its_documented_permission_set(): void
    {
        $this->seed(StaffSystemRolesSeeder::class);

        $role = app(RoleRepository::class)->findSystemRoleByName('Manager');

        self::assertNotNull($role);
        self::assertTrue($role->isSystem());
        self::assertEqualsCanonicalizing([
            Permission::PRODUCT_VIEW,
            Permission::PRODUCT_MANAGE,
            Permission::TAXONOMY_MANAGE,
            Permission::COST_VIEW,
            Permission::COST_MANAGE,
            Permission::PRICE_MANAGE,
            Permission::ORDER_VIEW,
            Permission::ORDER_MANAGE,
            Permission::REFUND_CASH,
            Permission::POS_OPERATE,
            Permission::POS_DISCOUNT,
            Permission::REPORT_VIEW,
        ], $role->permissions());
    }

    public function test_seeding_creates_the_product_entry_role_with_its_documented_permission_set(): void
    {
        $this->seed(StaffSystemRolesSeeder::class);

        $role = app(RoleRepository::class)->findSystemRoleByName('Product Entry');

        self::assertNotNull($role);
        self::assertTrue($role->isSystem());
        self::assertEqualsCanonicalizing([
            Permission::PRODUCT_VIEW,
            Permission::PRODUCT_MANAGE,
        ], $role->permissions());
    }

    public function test_running_the_seeder_twice_does_not_create_duplicates(): void
    {
        $this->seed(StaffSystemRolesSeeder::class);
        $this->seed(StaffSystemRolesSeeder::class);

        $count = DB::table('staff_roles')->where('is_system', true)->count();

        self::assertSame(3, $count);
    }

    public function test_database_seeder_actually_calls_the_staff_system_roles_seeder(): void
    {
        $this->seed();

        $count = DB::table('staff_roles')->where('is_system', true)->count();

        self::assertSame(3, $count);
    }
}
