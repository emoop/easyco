<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a PER_CLASS method reads its class amounts (shipping-domain-design.md §12.2): `replace` (the default and
     * what every existing method is — its behaviour does not change by a byte) or `adjust` (signed amounts added
     * to the base). A pure add: the default fills every existing row.
     *
     * CHECKs (MySQL/MariaDB, the supportsCheck() pattern; names explicit and short, CLAUDE.md rule 5):
     *  - ship_methods_class_mode_check: the value is one of the two;
     *  - ship_methods_adjust_kind_check: `adjust` only on a per_class method.
     * The other half of the rule — a class amount may be NEGATIVE only on an `adjust` method — compares a column of
     * ANOTHER table (shipping_method_class_rates.amount_minor), which a CHECK cannot do, and the existing amount
     * column is signed; it is enforced by the ShippingMethod entity (every write goes through it) and by the writer.
     */
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->string('class_mode', 10)->default('replace')->after('requires_pickup_point');
        });

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE shipping_methods ADD CONSTRAINT ship_methods_class_mode_check CHECK (class_mode IN ('replace', 'adjust'))");
            DB::statement("ALTER TABLE shipping_methods ADD CONSTRAINT ship_methods_adjust_kind_check CHECK (class_mode = 'replace' OR kind = 'per_class')");
        }
    }

    /** Refuses while any method adjusts: dropping the column would silently turn its signed amounts into replacement prices. */
    public function down(): void
    {
        if (Schema::hasColumn('shipping_methods', 'class_mode') && DB::table('shipping_methods')->where('class_mode', 'adjust')->exists()) {
            throw new RuntimeException('shipping_methods has methods in adjust mode; rolling back would turn their signed amounts into replacement prices. Nothing was changed.');
        }

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE shipping_methods DROP CONSTRAINT ship_methods_adjust_kind_check');
            DB::statement('ALTER TABLE shipping_methods DROP CONSTRAINT ship_methods_class_mode_check');
        }

        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn('class_mode');
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
