<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors season_id's exact FK posture on this same table
     * (2026_09_14_000003_add_season_id_to_catalog_products_table.php)
     * — nullable, nullOnDelete(): a Product survives its ProductGroup
     * being deleted, only the reference clears.
     */
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->foreignId('product_group_id')->nullable()
                ->after('season_id')
                ->constrained('catalog_product_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_group_id');
        });
    }
};
