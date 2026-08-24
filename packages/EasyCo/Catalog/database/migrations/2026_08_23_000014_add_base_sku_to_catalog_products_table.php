<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds catalog_products.base_sku in three steps so a dev/staging DB
     * that may already have rows (created before this column existed)
     * doesn't get rejected outright by a NOT NULL/UNIQUE constraint applied
     * in one shot:
     *   a) add nullable
     *   b) backfill existing rows with a synthesized, unique placeholder
     *   c) tighten to NOT NULL + UNIQUE now that every row has a value
     */
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->string('base_sku')->nullable();
        });

        DB::table('catalog_products')
            ->whereNull('base_sku')
            ->get(['id'])
            ->each(function (object $product): void {
                DB::table('catalog_products')
                    ->where('id', $product->id)
                    ->update(['base_sku' => 'LEGACY-'.$product->id]);
            });

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->string('base_sku')->nullable(false)->change();
        });

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->unique('base_sku', 'catalog_products_base_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropUnique('catalog_products_base_sku_unique');
            $table->dropColumn('base_sku');
        });
    }
};
