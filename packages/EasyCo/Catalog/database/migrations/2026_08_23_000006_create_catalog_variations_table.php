<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_variations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('catalog_products')
                // Restrict, not cascade: a Product is soft-deleted, never
                // hard-deleted, while it still has Variations — this FK is
                // a last-resort safety net against ever hard-deleting a
                // Product out from under live/historical Variations.
                ->restrictOnDelete();

            // universal | standard — see EasyCo\Catalog\Enums\VariationType.
            $table->string('type');

            // draft | active | archived — see EasyCo\Catalog\Enums\VariationStatus.
            // Distinct from is_visible/is_purchasable: a DRAFT or ARCHIVED
            // variation is never effectively purchasable regardless of the
            // flags below — see Variation::isEffectivelyPurchasable().
            $table->string('status')->default('draft');

            // Deterministic SHA-256 of the sorted (attribute_definition_id:
            // attribute_value_id) pairs that make up this variation's
            // combination — see EasyCo\Catalog\VariationSignature. For the
            // UNIVERSAL variation this is a fixed constant, which is what
            // makes "exactly one Universal variation per SIMPLE product"
            // fall out of the unique index below for free, with no extra
            // constraint needed.
            //
            // Computed in the application layer (not a DB generated
            // column): the source attribute/value pairs live in the child
            // table catalog_variation_attribute_values, and neither
            // MySQL/MariaDB nor SQLite support generated columns that read
            // another table's rows. The UNIQUE index below is still the
            // real, race-condition-safe enforcement — see
            // catalog-domain-design.md §"Variation combination uniqueness".
            $table->char('attribute_signature', 64);

            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();

            $table->boolean('is_visible')->default(true);
            $table->boolean('is_purchasable')->default(true);

            $table->text('short_description')->nullable();
            $table->string('shipping_class')->nullable();

            // Integers, not floats — millimetres/grams, same "no float
            // drift between two systems" reasoning Money applies to
            // currency (see easyco/pricing).
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            $table->timestamps();
            // Never hard-deleted — Orders/POS/Inventory may reference this
            // id forever. archive() (application layer) is the real
            // "removal" operation; this column exists as an extra safety
            // net only.
            $table->softDeletes();

            // THE core uniqueness guarantee: "Product X + this exact
            // attribute combination must not exist twice" — and, via the
            // fixed UNIVERSAL signature constant, "a SIMPLE product has
            // exactly one Universal variation".
            $table->unique(['product_id', 'attribute_signature'], 'catalog_variations_product_signature_unique');

            // Hot paths from the design doc:
            //   barcode -> variation / SKU -> variation
            // MySQL/MariaDB and SQLite all treat multiple NULLs as distinct
            // under a UNIQUE index, so this is "unique when present" without
            // needing a filtered/partial index.
            $table->unique('sku', 'catalog_variations_sku_unique');
            $table->unique('barcode', 'catalog_variations_barcode_unique');

            // product_id -> variations, filtered by lifecycle status (e.g.
            // "all ACTIVE variations of product X").
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_variations');
    }
};
