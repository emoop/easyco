<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('catalog_categories')->cascadeOnDelete();
            $table->unique(['product_id', 'category_id']);
        });

        Schema::create('catalog_product_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('catalog_tags')->cascadeOnDelete();
            $table->unique(['product_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_tags');
        Schema::dropIfExists('catalog_product_categories');
    }
};
