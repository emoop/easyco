<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The set of values the merchant has enabled for one of this product's
     * declared variation axes — what VariationCombinationGenerator draws
     * its cartesian product from.
     *
     * Application-layer invariant (not a DB constraint, since MySQL/MariaDB
     * cannot cross-reference another table's row in a CHECK constraint):
     * attribute_definition_id here must correspond to a
     * catalog_product_attributes row for the same product with
     * is_variation_axis = true. Enforced in the domain/application layer
     * and covered by tests — see catalog-domain-design.md.
     */
    public function up(): void
    {
        Schema::create('catalog_product_axis_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('catalog_products')->cascadeOnDelete();

            $table->foreignId('attribute_definition_id')
                ->constrained('catalog_attribute_definitions')->restrictOnDelete();

            $table->foreignId('attribute_value_id')
                ->constrained('catalog_attribute_values')->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['product_id', 'attribute_definition_id', 'attribute_value_id'],
                'catalog_product_axis_values_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_axis_values');
    }
};
