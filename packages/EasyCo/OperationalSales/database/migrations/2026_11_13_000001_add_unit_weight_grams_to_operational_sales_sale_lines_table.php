<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MEAS-1 (measurements-research.md §B): the variation's weight in whole GRAMS at the moment of the sale, an append-only
     * snapshot fact next to the other sold-line snapshots (price, cost, sold attributes). NULLABLE UNSIGNED INTEGER: NULL means
     * unknown (a line sold before this column existed, or a variation with no weight) and is never 0. No index (nothing filters by
     * it), so no identifier-length risk (CLAUDE.md rule 5). Idempotent (rule 6).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('operational_sales_sale_lines', 'unit_weight_grams')) {
            Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
                $table->unsignedInteger('unit_weight_grams')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('operational_sales_sale_lines', 'unit_weight_grams')) {
            Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
                $table->dropColumn('unit_weight_grams');
            });
        }
    }
};
