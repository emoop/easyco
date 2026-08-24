<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The single table answering both:
     *   - "which attributes describe this product, and what are their
     *     values?" (is_variation_axis = false)
     *   - "which attributes are this product's variation axes?"
     *     (is_variation_axis = true)
     *
     * ATTRIBUTE != VARIATION AXIS is enforced by this being a per-product
     * assignment, not a property of catalog_attribute_definitions itself
     * — see catalog-domain-design.md §"Attribute definition scope" for why
     * this single pivot was chosen over a Bagisto-style attribute-family
     * system.
     *
     * When is_variation_axis = true, text_value/attribute_value_id here
     * are irrelevant (deliberately left null) — the actual allowed axis
     * values live in catalog_product_axis_values, and each Variation's
     * chosen value per axis lives in catalog_variation_attribute_values.
     */
    public function up(): void
    {
        Schema::create('catalog_product_attributes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('catalog_products')->cascadeOnDelete();

            $table->foreignId('attribute_definition_id')
                ->constrained('catalog_attribute_definitions')->restrictOnDelete();

            $table->boolean('is_variation_axis')->default(false);

            // Descriptive-attribute value only (is_variation_axis = false).
            // One of the two is populated depending on the definition's
            // AttributeType — text_value for TEXT/NUMBER/BOOLEAN,
            // attribute_value_id for a single SELECT value. True
            // multiselect descriptive attributes are explicitly deferred
            // — see catalog-domain-design.md.
            $table->string('text_value')->nullable();
            $table->foreignId('attribute_value_id')->nullable()
                ->constrained('catalog_attribute_values')->restrictOnDelete();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(
               ['product_id', 'attribute_definition_id'],
               'catalog_product_attributes_product_attr_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_attributes');
    }
};
