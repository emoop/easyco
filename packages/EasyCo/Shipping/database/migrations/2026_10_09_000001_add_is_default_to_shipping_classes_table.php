<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The store's DEFAULT shipping class (shipping stage 5e): "used for new products and by the assign-missing
     * command". At most ONE class is the default — guaranteed by the database, not only by the writer, with the
     * project's marker-column technique (catalog_variations.sku, installment_plans.active_client_id): `default_marker`
     * is 1 on the default class and NULL on every other, and a plain UNIQUE index over it (NULLs are distinct on
     * MySQL, MariaDB and SQLite alike, so no partial index is needed). `is_default` is the readable flag; the CHECK
     * (MySQL/MariaDB, the supportsCheck() pattern; null-safe `<=>`, because a plain `= 1` on a NULL marker would
     * make the check UNKNOWN, which MySQL lets through) keeps the two in step. Explicit short names (CLAUDE.md rule 5).
     * A pure add: no existing class is a default.
     */
    public function up(): void
    {
        Schema::table('shipping_classes', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('description');
            $table->unsignedTinyInteger('default_marker')->nullable()->after('is_default');
            $table->unique('default_marker', 'ship_classes_default_unique');
        });

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE shipping_classes ADD CONSTRAINT ship_classes_default_marker_check CHECK ((is_default = 1 AND default_marker <=> 1) OR (is_default = 0 AND default_marker IS NULL))');
        }
    }

    public function down(): void
    {
        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE shipping_classes DROP CONSTRAINT ship_classes_default_marker_check');
        }

        Schema::table('shipping_classes', function (Blueprint $table) {
            $table->dropUnique('ship_classes_default_unique');
            $table->dropColumn(['is_default', 'default_marker']);
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
