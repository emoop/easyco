<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * restrictOnDelete(), not cascadeOnDelete() — mirrors
     * catalog_variations.product_id's own FK choice (never cascade,
     * never silently lose data tied to a variation), unlike Media's
     * pivot tables where cascadeOnDelete() is correct because a pivot
     * row has no reason to survive its parent. A stock count is a
     * different kind of relationship — see inventory-domain-design.md
     * §4.
     *
     * No softDeletes() — see inventory-domain-design.md §10.
     */
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')
                ->unique()
                ->constrained('catalog_variations')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
