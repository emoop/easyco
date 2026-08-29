<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Media is a first-class reference concept: Catalog owns the
     * relationship (this table + the two pivots below), never the
     * physical storage — url/storage_reference is treated as an opaque
     * pointer to whatever storage/CDN system owns the bytes. Deliberately
     * not coupled to a specific Laravel Filesystem disk here.
     */
    public function up(): void
    {
        Schema::create('catalog_media', function (Blueprint $table) {
            $table->id();

            // image | video | social_image | social_video
            $table->string('type');
            $table->string('url');
            $table->string('alt_text')->nullable();

            $table->timestamps();
        });

        Schema::create('catalog_product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('catalog_media')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['product_id', 'media_id']);
        });

        Schema::create('catalog_variation_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained('catalog_variations')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('catalog_media')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['variation_id', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_variation_media');
        Schema::dropIfExists('catalog_product_media');
        Schema::dropIfExists('catalog_media');
    }
};
