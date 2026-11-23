<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grants `Permission::CUSTOMER_VIEW` (`'customer_view'`) to an ALREADY-INSTALLED Administrator role ONLY — ACC-4e-1 (acc-4-gdpr-design.md §7). The admin «Клиенти» list and customer page
     * show personal data: Administrator only by default, never Manager.
     * The same reasoning and shape as 2026_10_09_000001_add_payment_reconcile_to_administrator_role: StaffSystemRolesSeeder::seedIfMissing() only ever CREATES a role, so fresh
     * installs get the permission from the seeder and an existing store needs this data migration. NARROW AND IDEMPOTENT: `WHERE name = 'Administrator' AND is_system = 1`;
     * a merchant's own role and every other role are never touched; a store without an Administrator role is a no-op. `down()` removes exactly this value.
     */
    private const PERMISSION = 'customer_view';

    private const ADMINISTRATOR_ROLE_NAME = 'Administrator';

    public function up(): void
    {
        $this->rewriteAdministratorPermissions(
            fn (array $permissions): array => in_array(self::PERMISSION, $permissions, true)
                ? $permissions
                : [...$permissions, self::PERMISSION]
        );
    }

    public function down(): void
    {
        $this->rewriteAdministratorPermissions(
            fn (array $permissions): array => array_values(array_diff($permissions, [self::PERMISSION]))
        );
    }

    /** @param callable(string[]): string[] $mutate */
    private function rewriteAdministratorPermissions(callable $mutate): void
    {
        $role = DB::table('staff_roles')
            ->where('name', self::ADMINISTRATOR_ROLE_NAME)
            ->where('is_system', true)
            ->first(['id', 'permissions']);

        if ($role === null) {
            return;
        }

        /** @var string[] $permissions */
        $permissions = json_decode((string) $role->permissions, true) ?? [];

        DB::table('staff_roles')
            ->where('id', $role->id)
            ->update(['permissions' => json_encode(array_values($mutate($permissions)))]);
    }
};
