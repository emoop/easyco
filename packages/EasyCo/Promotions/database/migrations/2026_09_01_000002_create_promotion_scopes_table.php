<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index/FK names (prefix "promo_scopes_"), same
     * "pp_scopes_"-style convention as
     * 2026_08_28_000002_create_pricing_price_list_scopes_table.php —
     * see that migration's docblock (CLAUDE.md rule 5).
     */
    public function up(): void
    {
        Schema::create('promotion_scopes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('promotion_id')
                // A scope row has no meaning without its parent
                // Promotion — same reasoning as
                // pricing_price_list_scopes.price_list_id.
                ->constrained('promotions', indexName: 'promo_scopes_promotion_id_foreign')
                ->cascadeOnDelete();

            // brand | category | tag | attribute_value | product |
            // account — see EasyCo\Promotions\Enums\PromotionScopeType.
            $table->string('scope_type');

            // Plain cross-domain id (into Catalog/Account/wherever) —
            // never a foreign key here, same convention as
            // pricing_price_list_scopes.scope_reference_id: this
            // package never validates that the referenced id actually
            // exists elsewhere.
            $table->string('scope_reference_id');

            // include | exclude — see
            // EasyCo\Promotions\Enums\PromotionScopeMode. The one real
            // structural addition PriceListScope has no equivalent of
            // (promotions-domain-design.md §3.1).
            $table->string('mode');

            $table->timestamps();

            // Hot path per §3's resolution rule: matching a cart's
            // lines/account against a Promotion's scope set. Same
            // reasoning as pp_scopes_type_ref_index.
            $table->index(['scope_type', 'scope_reference_id'], 'promo_scopes_type_ref_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_scopes');
    }
};
