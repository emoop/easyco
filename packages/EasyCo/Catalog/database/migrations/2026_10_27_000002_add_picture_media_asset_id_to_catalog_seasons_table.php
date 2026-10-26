<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CT-1.1: one picture for a season (`catalog_seasons` is its own entity, not an attribute value), chosen from the media library. The mirror of CT-1's categories and attribute values
 * (`2026_10_27_000001`): a nullable reference to `catalog_media`, RESTRICT on delete — a picture a season shows cannot be deleted from «Медия» (MediaDeleter asks the usage registry first; this foreign
 * key is the last guard). Deleting the season (a hard delete) just drops the reference; the picture stays in «Медия».
 *
 * Explicit short names (CLAUDE.md rule 5): cs_picture_media_idx / cs_picture_media_fk (not used by any other table: cc_ is the category's, cav_ the attribute value's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_seasons', function (Blueprint $table) {
            $table->unsignedBigInteger('picture_media_asset_id')->nullable()->after('slug');
            $table->index('picture_media_asset_id', 'cs_picture_media_idx');
            $table->foreign('picture_media_asset_id', 'cs_picture_media_fk')->references('id')->on('catalog_media')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_seasons', function (Blueprint $table) {
            $table->dropForeign('cs_picture_media_fk');
            $table->dropIndex('cs_picture_media_idx');
            $table->dropColumn('picture_media_asset_id');
        });
    }
};