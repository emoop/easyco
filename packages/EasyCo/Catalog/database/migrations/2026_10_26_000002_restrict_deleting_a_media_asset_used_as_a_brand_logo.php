<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ML-1b (media-library-design.md ML-D3): a media asset that is a brand's logo cannot be deleted: `catalog_brands.logo_media_asset_id` goes from SET NULL (the logo silently vanished) to
 * RESTRICT. Explicit short name (CLAUDE.md rule 5). If this stops half way (rule 6), check `SHOW CREATE TABLE catalog_brands` before running it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_brands', function (Blueprint $table) {
            $table->dropForeign('catalog_brands_logo_media_asset_id_foreign');
            $table->foreign('logo_media_asset_id', 'cb_logo_media_fk')->references('id')->on('catalog_media')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_brands', function (Blueprint $table) {
            $table->dropForeign('cb_logo_media_fk');
            $table->foreign('logo_media_asset_id', 'catalog_brands_logo_media_asset_id_foreign')->references('id')->on('catalog_media')->nullOnDelete();
        });
    }
};
