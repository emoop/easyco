<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grants `Permission::PRODUCT_DELETE` (`'product_delete'`) to an
     * ALREADY-INSTALLED Administrator role —
     * `staff-access-domain-design.md` §3/§3.1/§4.1,
     * `catalog-domain-design.md` §3.19.9.
     *
     * WHY A DATA MIGRATION IS NEEDED AT ALL: `StaffSystemRolesSeeder::
     * seedIfMissing()` only ever CREATES a role that does not exist — it
     * never adds a new permission to an existing one. Adding the enum case
     * and the seeder entry (both in this same change) therefore fixes FRESH
     * installs only; a store installed before this change keeps an
     * Administrator row without `'product_delete'`, and nothing else in the
     * codebase would ever repair it.
     *
     * HOW IT WRITES, GIVEN THE DOMAIN REFUSES TO:
     * `Role::updatePermissions()` throws `CannotModifySystemRoleException`
     * for any `isSystem()` role. That refusal is about a ROLE AS EDITED BY
     * A MERCHANT AT RUNTIME (§12.1: the shipped roles' permission sets are
     * load-bearing), and it is right. A migration is not that: it performs
     * the same act `StaffSystemRolesSeeder` performs on a fresh install —
     * writing the shipped default's own permission list — so it writes the
     * `staff_roles.permissions` json column directly rather than going
     * through the domain mutator that exists to stop a different thing.
     * It deliberately does NOT use `Role::reconstituteFromStorage()`
     * either: that factory is documented as persistence-layer-only, and a
     * migration that only needs to append one string to one json array has
     * no business reconstructing an aggregate.
     *
     * IDEMPOTENT AND NARROW: `WHERE name = 'Administrator' AND is_system =
     * 1` matches at most the one shipped Administrator role — a merchant's
     * own custom role that happens to be called "Administrator" is
     * `is_system = false` and is never touched, and no other role is
     * touched at all. Re-running with `'product_delete'` already present
     * writes the identical array back. A store with no Administrator role
     * (nothing installed yet) is a no-op — `StaffSystemRolesSeeder` will
     * create it complete.
     *
     * ARRAY ORDER IS NOT PART OF THE GUARANTEE: the value is appended when
     * absent, so an upgraded store's permission ORDER may differ from a
     * fresh install's. Nothing reads that order — `Role::permissions()` is
     * a set, and the admin's permission picker groups by its own rendering,
     * not by stored position.
     *
     * `down()` removes exactly this one value, leaving every other
     * permission untouched, and is a no-op when it is already absent.
     */
    private const PERMISSION = 'product_delete';

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
