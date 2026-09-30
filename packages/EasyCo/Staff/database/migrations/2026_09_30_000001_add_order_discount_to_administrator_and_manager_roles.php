<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grants `Permission::ORDER_DISCOUNT` (`'order_discount'`) to the two
     * ALREADY-INSTALLED system roles that hold it by default —
     * Administrator AND Manager (order-editing-design.md E5/§10 Q4, owner
     * decision: both roles). Permission gates the manual-discount cell of
     * the order edit dialog.
     *
     * WHY A DATA MIGRATION: `StaffSystemRolesSeeder::seedIfMissing()` only
     * creates a role that does not exist, so adding the enum case and the
     * seeder entries fixes FRESH installs only. This is the same migration
     * `2026_09_25_000001_add_product_delete_to_administrator_role` is, for
     * the same reasons (read its docblock for why the json column is written
     * directly rather than through `Role::updatePermissions()`, which refuses
     * system roles on purpose): generalised from one role to two.
     *
     * IDEMPOTENT AND NARROW: `WHERE name IN ('Administrator', 'Manager') AND
     * is_system = 1` matches only the two shipped roles — a merchant's own
     * custom role with either name is `is_system = false` and is never
     * touched, `Product Entry` and every other role are never touched. A
     * store with neither role is a no-op. Re-running with the value already
     * present writes the identical array back. Array order is not part of
     * the guarantee (the value is appended when absent).
     *
     * `down()` removes exactly this value from those two roles and is a
     * no-op where it is already absent.
     */
    private const PERMISSION = 'order_discount';

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
