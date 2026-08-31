<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * variation_id is restrictOnDelete(), matching stock_levels'
     * choice one level up (a real Variation can never be deleted
     * while a cart still references it) — unlike cart_id's
     * cascadeOnDelete() above it, which is correct because a
     * cart_lines row has no reason to survive its own parent cart.
     *
     * price_at_add_minor/price_at_add_currency are DISPLAY-ONLY — see
     * CartLine's own docblock and cart-domain-design.md §5. Nothing
     * may ever compute a total from these two columns.
     *
     * The composite unique is the DB-level enforcement of
     * Cart::addLine()'s one-line-per-variation invariant, for the
     * rare case of a genuinely concurrent double-add.
     */
    public function up(): void
    {
        Schema::create('cart_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained('catalog_variations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('price_at_add_minor')->nullable();
            $table->string('price_at_add_currency', 3)->nullable();
            $table->timestamps();
            $table->unique(['cart_id', 'variation_id'], 'cart_lines_cart_variation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_lines');
    }
};
