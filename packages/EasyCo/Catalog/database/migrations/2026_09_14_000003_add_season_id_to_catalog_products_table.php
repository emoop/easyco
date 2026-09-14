<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors brand_id's exact FK posture on this same table
     * (2026_08_23_000005_create_catalog_products_table.php) — nullable,
     * nullOnDelete(): a Product survives its Season being deleted, only
     * the reference clears.
     */
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->foreignId('season_id')->nullable()
                ->after('brand_id')
                ->constrained('catalog_seasons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('season_id');
        });
    }
};
