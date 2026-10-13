<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipping stage 5g-2a (shipping-domain-design.md 9.3.2 B): the KIND of a pickup destination — `office` or `locker` — on the
     * saved address, the order and the write-once placement snapshot, so the kind that priced an order is the kind the order
     * records. A plain validated string (the Address and Order packages import nothing from Shipping, CLAUDE.md rule 9).
     *
     * `pickup_point_kind` is VARCHAR(10) NULL on all three tables. Every existing row gets NULL, the honest value: "kind not
     * recorded" (a historical pickup address or order never stated one, and a historical address still checks out, priced as
     * "kind unspecified"). A street row never has one.
     *
     * One app-level migration rather than three package ones, exactly like stage 4f (2026_10_09_000004): the three tables belong to
     * two packages and the change is one decision. CLAUDE.md rule 2: the entity's rule ("only a pickup point, only office or
     * locker") is also a CHECK on MySQL/MariaDB, one per table, with explicit short names (rule 5): `addr_pickup_kind_check` (22
     * characters), `ord_pickup_kind_check` (21), `ops_pickup_kind_check` (21). The column is new, so every existing row satisfies it.
     * Other drivers keep only the entity's own rule.
     *
     * Idempotent (rule 6): a column or CHECK that is already there is left alone, in up() and in down(). MySQL DDL is not
     * transactional: after a failed run look at what exists before re-running.
     */
    public const TABLES = [
        'addresses' => 'addr_pickup_kind_check',
        'orders' => 'ord_pickup_kind_check',
        'order_placement_snapshots' => 'ops_pickup_kind_check',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasColumn($table, 'pickup_point_kind')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('pickup_point_kind', 10)->nullable();
                });
            }
        }

        if ($this->supportsCheck()) {
            foreach (self::TABLES as $table => $name) {
                if (! $this->hasCheck($table, $name)) {
                    DB::statement(
                        "ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ("
                        ."(pickup_point_kind IS NULL OR pickup_point_kind IN ('office', 'locker'))"
                        ." AND (delivery_type = 'pickup_point' OR pickup_point_kind IS NULL))"
                    );
                }
            }
        }
    }

    /** The checks first, then the columns. */
    public function down(): void
    {
        if ($this->supportsCheck()) {
            foreach (self::TABLES as $table => $name) {
                if ($this->hasCheck($table, $name)) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                }
            }
        }

        foreach (array_keys(self::TABLES) as $table) {
            if (Schema::hasColumn($table, 'pickup_point_kind')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('pickup_point_kind');
                });
            }
        }
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
