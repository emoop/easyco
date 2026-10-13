<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkout RN-1 (checkout-region-and-note-design.md section 4): the REGION (state / province) of a street address and the
     * customer's ORDER NOTE.
     *
     *  - `region` VARCHAR(100) NULL on `addresses`, `orders` and `order_placement_snapshots`: free text, a street address only
     *    (a pickup point has its settlement and the office's own address).
     *  - `customer_note` VARCHAR(1000) NULL on `orders` and `order_placement_snapshots` ONLY (the snapshot is what was agreed at
     *    placement): the customer's own words, written once at placement and never edited.
     *
     * Every existing row gets NULL, the honest value ("not recorded"). One app-level migration rather than three package ones,
     * exactly like stages 4f and 5g-2a: the tables belong to two packages and the change is one decision.
     *
     * CLAUDE.md rule 2: the entities' rules are also CHECKs on MySQL/MariaDB, with explicit short names (rule 5):
     * `addr_region_check` (17 characters), `ord_region_check` (16), `ops_region_check` (16) — a region is not blank and exists only
     * on a street row — and `ord_customer_note_check` (23), `ops_customer_note_check` (23) — a note is not blank. The columns are
     * new, so every existing row satisfies them. Other drivers keep only the entities' own rules.
     *
     * Idempotent (rule 6): a column or CHECK that is already there is left alone, in up() and in down(). MySQL DDL is not
     * transactional: after a failed run look at what exists before re-running.
     */
    public const COLUMNS = [
        'addresses' => ['region' => 100],
        'orders' => ['region' => 100, 'customer_note' => 1000],
        'order_placement_snapshots' => ['region' => 100, 'customer_note' => 1000],
    ];

    /** table => [check name => its expression] */
    public const CHECKS = [
        'addresses' => [
            'addr_region_check' => "(region IS NULL OR TRIM(region) <> '') AND (delivery_type = 'street_address' OR region IS NULL)",
        ],
        'orders' => [
            'ord_region_check' => "(region IS NULL OR TRIM(region) <> '') AND (delivery_type = 'street_address' OR region IS NULL)",
            'ord_customer_note_check' => "customer_note IS NULL OR TRIM(customer_note) <> ''",
        ],
        'order_placement_snapshots' => [
            'ops_region_check' => "(region IS NULL OR TRIM(region) <> '') AND (delivery_type = 'street_address' OR region IS NULL)",
            'ops_customer_note_check' => "customer_note IS NULL OR TRIM(customer_note) <> ''",
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $length) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($column, $length) {
                        $blueprint->string($column, $length)->nullable();
                    });
                }
            }
        }

        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $table => $checks) {
                foreach ($checks as $name => $expression) {
                    if (! $this->hasCheck($table, $name)) {
                        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
                    }
                }
            }
        }
    }

    /** The checks first, then the columns. */
    public function down(): void
    {
        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $table => $checks) {
                foreach (array_keys($checks) as $name) {
                    if ($this->hasCheck($table, $name)) {
                        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                    }
                }
            }
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($column) {
                        $blueprint->dropColumn($column);
                    });
                }
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
