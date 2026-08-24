<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();

            // SIMPLE | VARIABLE — see EasyCo\Catalog\Enums\ProductType.
            $table->string('type');

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();

            $table->foreignId('brand_id')->nullable()
                ->constrained('catalog_brands')->nullOnDelete();

            $table->foreignId('size_guide_id')->nullable()
                ->constrained('catalog_size_guides')->nullOnDelete();

            // draft | active | archived — product lifecycle, independent of
            // catalog_visibility. See ProductStatus / CatalogVisibility.
            $table->string('status')->default('draft');

            // visible | hidden — storefront/catalog display only. A hidden
            // product may still be sellable via POS/direct order — see
            // catalog-domain-design.md §"Visibility vs sellability".
            $table->string('catalog_visibility')->default('hidden');

            $table->boolean('is_featured')->default(false);

            $table->timestamps();
            // Soft-deleted, never hard-deleted — a Product may be referenced
            // historically even when retired from the live catalog.
            $table->softDeletes();

            $table->index(['status', 'catalog_visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
