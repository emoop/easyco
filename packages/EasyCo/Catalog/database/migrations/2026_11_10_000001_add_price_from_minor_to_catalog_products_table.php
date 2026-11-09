<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P6a: the price a listing is SORTED by — a derived, stored value (never what a customer pays): the lowest final price among the
     * product's AVAILABLE variations (priced, active, in stock), the lowest among all its priced shown variations when none is available,
     * NULL when nothing is priced. It is kept true by events, by the time boundaries of price lists and by a nightly pass
     * (App\Services\ProductSortPriceRefresher); the migration adds it EMPTY (`prices:refresh-from` fills it).
     *
     * The index name is explicit and short (CLAUDE.md rule 5).
     */
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->unsignedBigInteger('price_from_minor')->nullable();
            $table->index(['price_from_minor', 'id'], 'cat_products_price_from_idx');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropIndex('cat_products_price_from_idx');
            $table->dropColumn('price_from_minor');
        });
    }
};
