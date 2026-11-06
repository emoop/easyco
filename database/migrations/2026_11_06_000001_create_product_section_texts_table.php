<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P4b: the texts of a product for the «Секции на продукта» the merchant defines in «Дизайн»: one row per (product, section). `section_key` is the stable uuid of the section's definition (a section renamed or
     * moved keeps its texts; a section removed leaves its rows, which are not shown). An empty text is no row. The product is the domain's `catalog_products` row: deleting it deletes its texts.
     * Idempotent (CLAUDE.md rule 6): a table that is already there is left alone. Explicit short constraint names (rule 5).
     */
    public function up(): void
    {
        if (Schema::hasTable('product_section_texts')) {
            return;
        }

        Schema::create('product_section_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('catalog_products', 'id', 'pst_product_fk')->cascadeOnDelete();
            $table->char('section_key', 36);
            $table->text('body');
            $table->timestamps();

            $table->unique(['product_id', 'section_key'], 'pst_product_section_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_section_texts');
    }
};
