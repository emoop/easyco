<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index name (prefix "promo_"), same "pp_lists_"/
     * "os_sale_lines_"-style convention as Pricing's/OperationalSales's
     * own migrations — see those migrations' docblocks for the MySQL
     * 64-character identifier limit lesson (CLAUDE.md rule 5).
     *
     * `code`'s uniqueness is a PLAIN unique index over an
     * application-normalized (lowercased) value — not a DB-collation
     * case-insensitive index. Matches `accounts.email`'s approach
     * exactly (see 2026_08_31_000001_create_accounts_table.php): the
     * one other case-insensitive-unique string column in this project
     * also relies on Promotion/Account normalizing to lowercase before
     * every write and every lookup (Promotion::normalizeAndValidateCode(),
     * EloquentPromotionRepository::findByCode()), rather than a
     * collation trick at the schema level.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique('promo_promotions_code_unique');

            // percentage | fixed_amount — see
            // EasyCo\Promotions\Enums\PromotionDiscountType.
            $table->string('discount_type');

            // Basis points, 2000 = 20% — same convention as
            // EasyCo\Pricing\PriceList's percentage_basis_points. Only
            // meaningful when discount_type = percentage; null for
            // fixed_amount.
            $table->unsignedInteger('discount_percentage_basis_points')->nullable();

            // Money's actual public API (minorValue(): int, currency():
            // Currency), same _minor/_currency pair convention as
            // pricing_price_list_items.price_amount_minor/price_currency
            // and operational_sales_sale_lines.amount_minor/amount_currency.
            // Only meaningful when discount_type = fixed_amount.
            $table->bigInteger('discount_amount_minor')->nullable();
            $table->char('discount_amount_currency', 3)->nullable();

            $table->boolean('individual_use_only')->default(false);
            $table->boolean('exclude_sale_items')->default(false);

            $table->bigInteger('minimum_spend_amount_minor')->nullable();
            $table->char('minimum_spend_amount_currency', 3)->nullable();

            $table->bigInteger('maximum_spend_amount_minor')->nullable();
            $table->char('maximum_spend_amount_currency', 3)->nullable();

            $table->boolean('new_customers_only')->default(false);

            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('usage_limit_items')->nullable();

            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            // active | inactive — see EasyCo\Promotions\Enums\PromotionStatus.
            $table->string('status');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
