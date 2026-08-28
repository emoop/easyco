<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index/FK names (prefix "pp_items_") — same
     * convention as the two sibling migrations in this package; see
     * 2026_08_28_000001_create_pricing_price_lists_table.php's docblock.
     * pricing_price_list_items combined with the four-column lookup
     * index below is exactly the shape that has exceeded MySQL's
     * 64-character identifier limit elsewhere in this project (see
     * operational_sales_sale_lines' own docblock) — confirmed explicitly
     * for every name here, not assumed safe.
     */
    public function up(): void
    {
        Schema::create('pricing_price_list_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('price_list_id')
                ->constrained('pricing_price_lists', indexName: 'pp_items_price_list_id_foreign')
                ->cascadeOnDelete();

            // product | variation — see
            // EasyCo\Pricing\Enums\PriceListItemTargetType.
            $table->string('target_type');

            // Plain cross-domain id (a Catalog product_id or a
            // Variation's priceableId, per target_type) — never a
            // foreign key here, same §1 reasoning as
            // pricing_price_list_scopes.scope_reference_id.
            $table->string('target_id');

            $table->unsignedInteger('min_quantity')->default(1);

            // Price's actual public API (Money's minorValue()/currency()
            // plus Price's own tax-rate/inclusivity fields) — same
            // "store the primitives the domain class itself exposes"
            // approach operational_sales_sale_lines.amount_minor/
            // amount_currency already established for Money alone; this
            // table also has to carry Price's two additional fields.
            $table->bigInteger('price_amount_minor');
            $table->char('price_currency', 3);
            $table->unsignedInteger('price_tax_rate_basis_points');
            $table->boolean('price_tax_inclusive');

            $table->timestamps();

            // Hot path per §5: PriceListItem's fallback-then-tier
            // resolution (§4.3 — VARIATION-level checked before falling
            // back to PRODUCT-level; §4.4 — the highest min_quantity
            // tier still <= the requested quantity).
            $table->index(
                ['price_list_id', 'target_type', 'target_id', 'min_quantity'],
                'pp_items_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_price_list_items');
    }
};
