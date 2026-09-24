<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * operational-sales-domain-design.md §3.13 — the full price-level/
     * discount/attribute/cost snapshot needed for returns. Every Money
     * field follows the SAME `<name>_minor` + `<name>_currency` convention
     * this table already established for `amount`/`profit`
     * (2026_08_25_000004_create_operational_sales_sale_lines_table.php) —
     * no documented reason found to deviate, so none was invented; a
     * single shared "currency" column was considered and rejected for the
     * same reason `amount`/`profit` already don't share one: each Money
     * value stays a fully self-describing pair, exactly like every other
     * Money column in this project.
     *
     * ALL NULLABLE, NO BACKFILL — §3.13 E-D5: required only for a
     * freshly-created SALE line via the new SaleLine::create(); a row
     * written before this migration stays NULL forever
     * (reconstituteFromStorage() accepts that — see this stage's own
     * SaleLine.php changes). `unit_cost_*` stays nullable even on a FRESH
     * row (§3.13 Q2 — NULL means genuinely unknown, not zero).
     *
     * `sold_attributes` — a nullable JSON column (§3.13 Q3's own
     * recommendation): an ordered list of
     * {definitionId, definitionCode, definitionName, valueId, value},
     * `[]` (not NULL) for a SIMPLE line once §3.13 fields are populated,
     * NULL only for a pre-migration row.
     */
    public function up(): void
    {
        Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
            $table->bigInteger('regular_unit_price_minor')->nullable()->after('sku');
            $table->char('regular_unit_price_currency', 3)->nullable()->after('regular_unit_price_minor');

            $table->bigInteger('final_unit_price_minor')->nullable()->after('regular_unit_price_currency');
            $table->char('final_unit_price_currency', 3)->nullable()->after('final_unit_price_minor');

            $table->bigInteger('promotion_discount_share_minor')->nullable()->after('final_unit_price_currency');
            $table->char('promotion_discount_share_currency', 3)->nullable()->after('promotion_discount_share_minor');

            $table->bigInteger('discretionary_discount_minor')->nullable()->after('promotion_discount_share_currency');
            $table->char('discretionary_discount_currency', 3)->nullable()->after('discretionary_discount_minor');

            $table->bigInteger('net_paid_amount_minor')->nullable()->after('discretionary_discount_currency');
            $table->char('net_paid_amount_currency', 3)->nullable()->after('net_paid_amount_minor');

            $table->bigInteger('unit_cost_minor')->nullable()->after('net_paid_amount_currency');
            $table->char('unit_cost_currency', 3)->nullable()->after('unit_cost_minor');

            $table->json('sold_attributes')->nullable()->after('unit_cost_currency');
        });
    }

    public function down(): void
    {
        Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
            $table->dropColumn([
                'regular_unit_price_minor',
                'regular_unit_price_currency',
                'final_unit_price_minor',
                'final_unit_price_currency',
                'promotion_discount_share_minor',
                'promotion_discount_share_currency',
                'discretionary_discount_minor',
                'discretionary_discount_currency',
                'net_paid_amount_minor',
                'net_paid_amount_currency',
                'unit_cost_minor',
                'unit_cost_currency',
                'sold_attributes',
            ]);
        });
    }
};
