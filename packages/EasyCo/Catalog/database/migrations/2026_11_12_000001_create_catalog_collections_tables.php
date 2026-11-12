<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P6e-1: «Колекции» — a collection (name, slug, ONE picture: the season's shape) that a product can be in MANY of (the tag's pivot shape). The pivot cascades on both sides like catalog_product_tags: deleting a
     * collection or a product removes its pivot rows; the picture reference is RESTRICT like the season's (a picture a collection shows cannot be deleted from «Медия»).
     *
     * Explicit short names (CLAUDE.md rule 5): cc_ is the category's, cs_ the season's; this table uses ccol_ / cpcol_.
     */
    public function up(): void
    {
        Schema::create('catalog_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique('ccol_slug_unique');
            $table->unsignedBigInteger('picture_media_asset_id')->nullable();
            $table->timestamps();

            $table->index('picture_media_asset_id', 'ccol_picture_media_idx');
            $table->foreign('picture_media_asset_id', 'ccol_picture_media_fk')->references('id')->on('catalog_media')->restrictOnDelete();
        });

        Schema::create('catalog_product_collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('collection_id');

            $table->unique(['product_id', 'collection_id'], 'cpcol_product_collection_unique');
            $table->index('collection_id', 'cpcol_collection_idx');
            $table->foreign('product_id', 'cpcol_product_fk')->references('id')->on('catalog_products')->cascadeOnDelete();
            $table->foreign('collection_id', 'cpcol_collection_fk')->references('id')->on('catalog_collections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_collections');
        Schema::dropIfExists('catalog_collections');
    }
};
