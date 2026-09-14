<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * nullOnDelete(), not cascadeOnDelete() — a Brand must survive its
     * logo being deleted, only the reference should go away. This looks
     * different from catalog_product_media/catalog_variation_media's own
     * cascadeOnDelete() on media_id, but the effect is the same: those
     * FKs live on pivot ROWS whose deletion never touches the owning
     * Product/Variation at all — cascading just removes the association
     * row. logo_media_asset_id is a direct column on catalog_brands
     * itself, so the equivalent "association disappears, owner survives"
     * behavior here is nullOnDelete(), not cascadeOnDelete() (which would
     * delete the whole Brand row).
     */
    public function up(): void
    {
        Schema::table('catalog_brands', function (Blueprint $table) {
            $table->foreignId('logo_media_asset_id')
                ->nullable()
                ->after('slug')
                ->constrained('catalog_media')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_brands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logo_media_asset_id');
        });
    }
};
