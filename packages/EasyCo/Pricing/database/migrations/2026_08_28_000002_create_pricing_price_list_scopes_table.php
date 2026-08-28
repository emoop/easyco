<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index/FK names (prefix "pp_scopes_"), same
     * "os_sale_lines_"-style convention as
     * 2026_08_28_000001_create_pricing_price_lists_table.php — see that
     * migration's docblock.
     */
    public function up(): void
    {
        Schema::create('pricing_price_list_scopes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('price_list_id')
                // Ordinary PriceLists are genuinely hard-deletable (unlike
                // Catalog Products, which are never hard-deleted per
                // catalog-domain-design.md §3.4/§4). A scope row has no
                // meaning without its parent list, so cascadeOnDelete()
                // is correct here, not restrictOnDelete().
                ->constrained('pricing_price_lists', indexName: 'pp_scopes_price_list_id_foreign')
                ->cascadeOnDelete();

            // brand | category | tag | attribute_value | customer_group |
            // channel | product — see
            // EasyCo\Pricing\Enums\PriceListScopeType.
            $table->string('scope_type');

            // Plain cross-domain id (into Catalog/OperationalSales/
            // wherever) — never a foreign key here, per
            // pricing-persistence-domain-design.md §1: this package
            // never validates that the referenced id actually exists
            // elsewhere.
            $table->string('scope_reference_id');

            $table->timestamps();

            // Hot path per §5: resolve()'s scope-matching query.
            $table->index(['scope_type', 'scope_reference_id'], 'pp_scopes_type_ref_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_price_list_scopes');
    }
};
