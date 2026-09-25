<?php

namespace EasyCo\Staff\Seeders;

use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the three reserved system Roles — Administrator, Manager,
 * Product Entry — every store must have, per
 * staff-access-domain-design.md §4.1. The permission lists below are
 * copied verbatim from that section's explicit per-role "Granted:"
 * lists (cross-checked against §4.1's own comparison table; both agree).
 *
 * Idempotent by design: findSystemRoleByName() is checked before each
 * create, so re-running this seeder (e.g. on every deploy) never
 * produces a duplicate. Mirrors PricingSystemListsSeeder's exact shape.
 */
class StaffSystemRolesSeeder extends Seeder
{
    public function run(RoleRepository $roleRepository): void
    {
        $this->seedIfMissing($roleRepository, 'Administrator', [
            Permission::PRODUCT_VIEW,
            Permission::PRODUCT_MANAGE,
            Permission::PRODUCT_DELETE,
            Permission::TAXONOMY_MANAGE,
            Permission::COST_VIEW,
            Permission::COST_MANAGE,
            Permission::PRICE_MANAGE,
            Permission::ORDER_VIEW,
            Permission::ORDER_MANAGE,
            Permission::REFUND_CASH,
            Permission::REFUND_BANK,
            Permission::POS_OPERATE,
            Permission::POS_DISCOUNT,
            Permission::PROMOTION_MANAGE,
            Permission::REPORT_VIEW,
            Permission::SETTINGS_MANAGE,
            Permission::STAFF_MANAGE,
            Permission::AI_MANAGE,
        ]);

        $this->seedIfMissing($roleRepository, 'Manager', [
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
        ]);

        $this->seedIfMissing($roleRepository, 'Product Entry', [
            Permission::PRODUCT_VIEW,
            Permission::PRODUCT_MANAGE,
        ]);
    }

    /** @param Permission[] $permissions */
    private function seedIfMissing(RoleRepository $roleRepository, string $name, array $permissions): void
    {
        if ($roleRepository->findSystemRoleByName($name) !== null) {
            return;
        }

        $roleRepository->save(Role::createSystemRole($name, $permissions));
    }
}
