<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A STANDARD variation's chosen value for each of its product's axes
     * — the source data VariationSignature hashes into
     * catalog_variations.attribute_signature. Never populated for a
     * UNIVERSAL variation.
     */
    public function up(): void
    {
        Schema::create('catalog_variation_attribute_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('variation_id')
                ->constrained('catalog_variations')->cascadeOnDelete();

            $table->foreignId('attribute_definition_id')
                ->constrained(
                    table: 'catalog_attribute_definitions',
                    indexName: 'catalog_variation_attr_values_attr_def_foreign'
                )->restrictOnDelete();

            $table->foreignId('attribute_value_id')
                ->constrained('catalog_attribute_values')->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['variation_id', 'attribute_definition_id'],
                'catalog_variation_attr_values_variation_attr_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_variation_attribute_values');
    }
};