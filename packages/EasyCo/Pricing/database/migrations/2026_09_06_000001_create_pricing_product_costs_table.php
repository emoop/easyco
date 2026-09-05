<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index/FK name prefix "pp_costs_" — same convention
     * as this package's other three migrations (pp_lists_/pp_scopes_/
     * pp_items_); see 2026_08_28_000001_create_pricing_price_lists_table.php's
     * docblock for the MySQL 64-character identifier limit lesson.
     */
    public function up(): void
    {
        Schema::create('pricing_product_costs', function (Blueprint $table) {
            $table->id();

            // Plain cross-domain id (a Catalog Variation's priceableId)
            // — never a foreign key here, same §1 reasoning as
            // pricing_price_list_items.target_id: this package never
            // validates that the referenced priceable actually exists
            // in Catalog.
            $table->string('priceable_id');

            // Store the primitives Money itself exposes — same "store
            // what the domain class exposes" approach this package's
            // own price_list_items migration already took for Price.
            $table->bigInteger('cost_amount_minor');
            $table->char('cost_currency', 3);

            $table->timestamps();

            // At most one cost row per (priceable, currency) pair —
            // this is what makes costFor()'s lookup and
            // ProductCost::updateCost()'s currency-lock (see the
            // entity) both well-formed.
            $table->unique(['priceable_id', 'cost_currency'], 'pp_costs_priceable_currency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_product_costs');
    }
};
