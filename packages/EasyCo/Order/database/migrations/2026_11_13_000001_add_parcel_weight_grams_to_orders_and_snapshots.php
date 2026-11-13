<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MEAS-1 (measurements-research.md §B): the parcel weight in whole GRAMS at placement, on the order and on its write-once
     * placement snapshot = the sum of the sold lines' weights when EVERY line has one, else NULL (unknown, never 0; existing orders
     * stay NULL). NULLABLE UNSIGNED INTEGER, no index (nothing filters by it; no identifier-length risk, CLAUDE.md rule 5).
     * Idempotent (rule 6).
     */
    public const TABLES = ['orders', 'order_placement_snapshots'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'parcel_weight_grams')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedInteger('parcel_weight_grams')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'parcel_weight_grams')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('parcel_weight_grams');
                });
            }
        }
    }
};
