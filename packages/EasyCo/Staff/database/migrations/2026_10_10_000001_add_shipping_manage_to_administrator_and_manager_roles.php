<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grants `Permission::SHIPPING_MANAGE` (`'shipping_manage'`) to the two
     * ALREADY-INSTALLED system roles that hold it by default — Administrator AND
     * Manager (shipping-domain-design.md §12.6: a manager configures shipping).
     *
     * WHY A DATA MIGRATION: `StaffSystemRolesSeeder::seedIfMissing()` only creates
     * a role that does not exist, so adding the enum case and the seeder entries
     * fixes FRESH installs only. This is the same migration
     * `2026_09_30_000001_add_order_discount_to_administrator_and_manager_roles`
     * is, for the same reasons (read its docblock for why the json column is
     * written directly rather than through `Role::updatePermissions()`, which
     * refuses system roles on purpose).
     *
     * IDEMPOTENT AND NARROW: `WHERE name IN ('Administrator', 'Manager') AND
     * is_system = 1` matches only the two shipped roles — a merchant's own custom
     * role with either name is `is_system = false` and is never touched, `Product
     * Entry` and every other role are never touched. A store with neither role is
     * a no-op. Re-running with the value already present writes the identical
     * array back (the value is appended when absent; array order is not part of
     * the guarantee).
     *
     * `down()` removes exactly this value from those two roles and is a no-op
     * where it is already absent.
     */
    private const PERMISSION = 'shipping_manage';

    private const ROLE_NAMES = ['Administrator', 'Manager'];

    public function up(): void
    {
        $this->rewritePermissions(
            fn (array $permissions): array => in_array(self::PERMISSION, $permissions, true)
                ? $permissions
                : [...$permissions, self::PERMISSION]
        );
    }

    public function down(): void
    {
        $this->rewritePermissions(
            fn (array $permissions): array => array_values(array_diff($permissions, [self::PERMISSION]))
        );
    }

    /** @param callable(string[]): string[] $mutate */
    private function rewritePermissions(callable $mutate): void
    {
        $roles = DB::table('staff_roles')
            ->whereIn('name', self::ROLE_NAMES)
            ->where('is_system', true)
            ->get(['id', 'permissions']);

        foreach ($roles as $role) {
            /** @var string[] $permissions */
            $permissions = json_decode((string) $role->permissions, true) ?? [];

            DB::table('staff_roles')
                ->where('id', $role->id)
                ->update(['permissions' => json_encode(array_values($mutate($permissions)))]);
        }
    }
};
