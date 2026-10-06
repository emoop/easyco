<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grants `Permission::PAYMENT_RECONCILE` (`'payment_reconcile'`) to an ALREADY-INSTALLED
     * Administrator role ONLY — refunds R4a-3, shipping-domain-design.md §7.2.20 §5. Accepting a bank
     * transfer that is short or over gives money away or takes it on, so it is a money decision like
     * `refund_bank`: Administrator, never Manager.
     *
     * The same reasoning and the same shape as 2026_09_25_000001_add_product_delete_to_administrator_role:
     * StaffSystemRolesSeeder::seedIfMissing() only ever CREATES a role, so fresh installs get the
     * permission from the seeder (changed in the same commit) and an existing store needs this data
     * migration. It writes the `staff_roles.permissions` json column directly (the domain refuses to
     * edit a system role at runtime, which is about a merchant editing it, not about a migration).
     *
     * NARROW AND IDEMPOTENT: `WHERE name = 'Administrator' AND is_system = 1` matches the one shipped
     * role; a merchant's own custom role of that name and every other role are never touched. Running
     * it again writes the same array back. A store without an Administrator role is a no-op.
     * `down()` removes exactly this value and is a no-op when it is already absent.
     */
    private const PERMISSION = 'payment_reconcile';

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
