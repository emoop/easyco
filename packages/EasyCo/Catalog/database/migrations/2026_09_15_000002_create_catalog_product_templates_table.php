<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            $table->foreignId('brand_id')->nullable()
                ->constrained('catalog_brands')->nullOnDelete();

            $table->foreignId('season_id')->nullable()
                ->constrained('catalog_seasons')->nullOnDelete();

            $table->foreignId('product_group_id')->nullable()
                ->constrained('catalog_product_groups')->nullOnDelete();

            // Plain JSON arrays of ids — no pivot table, no FK integrity:
            // a template's category/tag list only needs "these ids
            // existed when the template was saved" (catalog-domain-
            // design.md §3.16's own "smallest model" reasoning, §3.3's
            // precedent).
            $table->json('category_ids')->nullable();
            $table->json('tag_ids')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_templates');
    }
};
