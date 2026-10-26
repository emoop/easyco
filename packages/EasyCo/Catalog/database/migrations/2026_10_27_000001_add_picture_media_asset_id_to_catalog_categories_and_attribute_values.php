<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CT-1: one picture for a category and one for an attribute value (a season, a colour ...), chosen from the media library. Mirrors the brand's logo as ML-1b left it
 * (`catalog_brands.logo_media_asset_id`, `cb_logo_media_fk`): a nullable reference to `catalog_media`, RESTRICT on delete — a picture that a category or a value shows cannot be deleted from
 * «Медия» (MediaDeleter asks the usage registry first; this foreign key is the last guard). Deleting the category or the value just drops the reference; the picture stays in «Медия».
 *
 * Explicit short names (CLAUDE.md rule 5: MySQL identifiers are 64 characters at most): cc_picture_media_fk / cc_picture_media_idx and cav_picture_media_fk / cav_picture_media_idx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('picture_media_asset_id')->nullable()->after('slug');
            $table->index('picture_media_asset_id', 'cc_picture_media_idx');
            $table->foreign('picture_media_asset_id', 'cc_picture_media_fk')->references('id')->on('catalog_media')->restrictOnDelete();
        });

        Schema::table('catalog_attribute_values', function (Blueprint $table) {
            $table->unsignedBigInteger('picture_media_asset_id')->nullable()->after('sort_order');
            $table->index('picture_media_asset_id', 'cav_picture_media_idx');
            $table->foreign('picture_media_asset_id', 'cav_picture_media_fk')->references('id')->on('catalog_media')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_attribute_values', function (Blueprint $table) {
            $table->dropForeign('cav_picture_media_fk');
            $table->dropIndex('cav_picture_media_idx');
            $table->dropColumn('picture_media_asset_id');
        });

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->dropForeign('cc_picture_media_fk');
            $table->dropIndex('cc_picture_media_idx');
            $table->dropColumn('picture_media_asset_id');
        });
    }
};
