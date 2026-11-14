<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MEAS-4 (owner decision 2026-11-14): a template has a KIND, and a product can have ONE row for the whole product.
     *
     *  - catalog_measurement_templates.kind: `by_size` (default; every existing template) | `single`. String 16, NOT NULL.
     *  - catalog_measurement_values.size_value_id becomes NULLABLE (NULL = the product's one row). The FK stays.
     *  - Uniqueness stays a real database constraint: a STORED generated column row_slot = COALESCE(size_value_id, 0) and a unique (product_id, row_slot, measure_id) (names cmv_cell_slot_unique) that
     *    replaces cmv_cell_unique. The new unique is added BEFORE the old one is dropped: on MySQL the product FK needs an index that starts with product_id at every moment.
     *
     * Idempotent step by step (CLAUDE.md rule 6: MySQL DDL is not transactional, a half-run migration must be re-runnable).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_measurement_templates', 'kind')) {
            Schema::table('catalog_measurement_templates', function (Blueprint $table) {
                $table->string('kind', 16)->default('by_size');
            });
        }

        // nullable size_value_id (the FK keeps pointing at catalog_attribute_values, RESTRICT)
        Schema::table('catalog_measurement_values', function (Blueprint $table) {
            $table->unsignedBigInteger('size_value_id')->nullable()->change();
        });

        if (! Schema::hasColumn('catalog_measurement_values', 'row_slot')) {
            Schema::table('catalog_measurement_values', function (Blueprint $table) {
                $table->unsignedBigInteger('row_slot')->storedAs('COALESCE(size_value_id, 0)');
            });
        }

        if (! $this->hasIndex('cmv_cell_slot_unique')) {
            Schema::table('catalog_measurement_values', function (Blueprint $table) {
                $table->unique(['product_id', 'row_slot', 'measure_id'], 'cmv_cell_slot_unique');
            });
        }

        if ($this->hasIndex('cmv_cell_unique')) {
            Schema::table('catalog_measurement_values', function (Blueprint $table) {
                $table->dropUnique('cmv_cell_unique');
            });
        }
    }

    public function down(): void
    {
        // Rows of the single kind (NULL size value) cannot exist in the old shape: they are removed on the way down.
        DB::table('catalog_measurement_values')->whereNull('size_value_id')->delete();

        if (! $this->hasIndex('cmv_cell_unique')) {
            Schema::table('catalog_measurement_values', function (Blueprint $table) {
                $table->unique(['product_id', 'size_value_id', 'measure_id'], 'cmv_cell_unique');
            });
        }

        if ($this->hasIndex('cmv_cell_slot_unique')) {
            Schema::table('catalog_measurement_values', function (Blueprint $table) {
                $table->dropUnique('cmv_cell_slot_unique');
            });
        }

        if (Schema::hasColumn('catalog_measurement_values', 'row_slot')) {
            Schema::table('catalog_measurement_values', function (Blueprint $table) {
                $table->dropColumn('row_slot');
            });
        }

        Schema::table('catalog_measurement_values', function (Blueprint $table) {
            $table->unsignedBigInteger('size_value_id')->nullable(false)->change();
        });

        if (Schema::hasColumn('catalog_measurement_templates', 'kind')) {
            Schema::table('catalog_measurement_templates', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return Schema::hasIndex('catalog_measurement_values', $name);
    }
};
