<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P6c: «does the product have at least one SHOWN variation with stock > 0?» — a derived, stored fact (never what a customer may buy: the purchase rules read the real stock). The storefront's listings,
     * counts and facets add ONE indexed condition on it while the shop hides sold-out products. It is kept true by App\Services\PriceSort\ProductSortPriceRefresher in the SAME pass as `price_from_minor`
     * (events, time boundaries, the nightly `prices:refresh-from`).
     *
     * The default is TRUE: nothing disappears from a listing before the first refresh has run. The index follows the default listing order (newest first); its name is explicit and short (CLAUDE.md rule 5).
     */
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->boolean('is_available')->default(true);
            $table->index(['is_available', 'timeline_at', 'id'], 'cat_products_avail_idx');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropIndex('cat_products_avail_idx');
            $table->dropColumn('is_available');
        });
    }
};
