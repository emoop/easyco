<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tightens catalog_variations.sku to NOT NULL, the same safe way
     * catalog_products.base_sku was tightened
     * (2026_08_23_000014_add_base_sku_to_catalog_products_table.php):
     *   a) backfill any existing NULL sku rows with a synthesized, unique
     *      placeholder value
     *   b) alter the column to NOT NULL
     * The existing UNIQUE(sku) index (catalog_variations_sku_unique, from
     * 2026_08_23_000006_create_catalog_variations_table.php) is left as-is
     * — it already tolerates multiple NULLs and continues to enforce
     * uniqueness once every row has a real value.
     */
    public function up(): void
    {
        DB::table('catalog_variations')
            ->whereNull('sku')
            ->get(['id'])
            ->each(function (object $variation): void {
                DB::table('catalog_variations')
                    ->where('id', $variation->id)
                    ->update(['sku' => 'LEGACY-VAR-'.$variation->id]);
            });

        Schema::table('catalog_variations', function (Blueprint $table) {
            $table->string('sku')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_variations', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
        });
    }
};
