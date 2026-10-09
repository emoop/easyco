<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipping stage 4f (shipping-domain-design.md section 9.1.6): the pickup point's DISPLAY SNAPSHOT — the office's name and
     * address as the customer saw them — on the saved address, the order and the write-once placement snapshot, so an order
     * still reads correctly if the courier renames or closes the office. Display text only: the pickup_point_reference stays the
     * identifier, and nothing verifies these two against a courier.
     *
     * Both columns are VARCHAR(255) NULL on all three tables. Every existing row gets NULL, which is the honest value (a
     * historical pickup address or order never recorded them), and a historical pickup address stays usable for checkout.
     *
     * One app-level migration rather than three package ones, because the three tables belong to two packages (Address, Order) and
     * the change is one decision. CLAUDE.md rule 2: the entity's rule ("a street address has neither; when given each is not blank")
     * is also a CHECK on MySQL/MariaDB, one per table, with explicit short names (rule 5):
     * `addr_pickup_display_check` (25 characters), `ord_pickup_display_check` (24), `ops_pickup_display_check` (24). The
     * columns are new, so every existing row satisfies the CHECK. Other drivers keep only the entity's own rule.
     *
     * Idempotent (rule 6): a column or CHECK that is already there is left alone, in up() and in down().
     */
    private const TABLES = [
        'addresses' => 'addr_pickup_display_check',
        'orders' => 'ord_pickup_display_check',
        'order_placement_snapshots' => 'ops_pickup_display_check',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'pickup_point_name')) {
                    $blueprint->string('pickup_point_name', 255)->nullable();
                }
                if (! Schema::hasColumn($table, 'pickup_point_address')) {
                    $blueprint->string('pickup_point_address', 255)->nullable();
                }
            });
        }

        if ($this->supportsCheck()) {
            foreach (self::TABLES as $table => $name) {
                if (! $this->hasCheck($table, $name)) {
                    DB::statement(
                        "ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ("
                        ."(pickup_point_name IS NULL OR TRIM(pickup_point_name) <> '')"
                        ." AND (pickup_point_address IS NULL OR TRIM(pickup_point_address) <> '')"
                        ." AND (delivery_type = 'pickup_point' OR (pickup_point_name IS NULL AND pickup_point_address IS NULL)))"
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
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $present = array_values(array_filter(
                    ['pickup_point_address', 'pickup_point_name'],
                    fn (string $column): bool => Schema::hasColumn($table, $column),
                ));

                if ($present !== []) {
                    $blueprint->dropColumn($present);
                }
            });
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
