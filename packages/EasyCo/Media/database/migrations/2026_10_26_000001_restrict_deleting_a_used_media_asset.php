<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ML-1b (media-library-design.md ML-D3): the DATABASE refuses to delete a media asset that a product or a variation still shows (CLAUDE.md rule 2). Until now deleting the `catalog_media` row
 * silently CASCADED the photo out of the product. The `media_id` side becomes RESTRICT; the `product_id` / `variation_id` side is not touched, so deleting a product (or a variation) still
 * removes its own pivot rows and leaves the asset in the library, unused.
 *
 * Explicit short constraint names (rule 5). The old auto-generated names are dropped first: MySQL's DDL is not transactional (rule 6), so if this ever stops half way, look at
 * `SHOW CREATE TABLE` of both tables before running it again (the second pivot may still have its old constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_product_media', function (Blueprint $table) {
            $table->dropForeign('catalog_product_media_media_id_foreign');
            $table->foreign('media_id', 'cpm_media_fk')->references('id')->on('catalog_media')->restrictOnDelete();
        });

        Schema::table('catalog_variation_media', function (Blueprint $table) {
            $table->dropForeign('catalog_variation_media_media_id_foreign');
            $table->foreign('media_id', 'cvm_media_fk')->references('id')->on('catalog_media')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_product_media', function (Blueprint $table) {
            $table->dropForeign('cpm_media_fk');
            $table->foreign('media_id', 'catalog_product_media_media_id_foreign')->references('id')->on('catalog_media')->cascadeOnDelete();
        });

        Schema::table('catalog_variation_media', function (Blueprint $table) {
            $table->dropForeign('cvm_media_fk');
            $table->foreign('media_id', 'catalog_variation_media_media_id_foreign')->references('id')->on('catalog_media')->cascadeOnDelete();
        });
    }
};
