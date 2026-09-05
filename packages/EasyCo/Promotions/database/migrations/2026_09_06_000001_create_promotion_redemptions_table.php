<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index/FK names (prefix "promo_redemptions_"), same
     * convention as this package's own promotion_scopes migration.
     *
     * promotion_id is restrictOnDelete() — DELIBERATELY DIFFERENT from
     * promotion_scopes.promotion_id's own cascadeOnDelete(). A
     * PromotionScope is a config row with no meaning without its parent
     * (deleting the Promotion should take its scopes with it); a
     * PromotionRedemption is a historical fact — an order really did use
     * this code — with independent historical meaning, the same
     * "must never silently vanish" reasoning orders.client_id/
     * transaction_id already established (checkout-domain-design.md
     * Step 1b).
     *
     * order_id is a REAL foreign key, unlike Payment.order_id (a
     * deliberately plain string, because the Order domain didn't exist
     * yet when Payment was built — payment-domain-design.md §6). Order
     * exists now (Step 1b already shipped) — there is no more reason
     * for THIS new reference to be a loose string. NOTE: this makes
     * Payment.order_id remaining a plain string an inconsistency worth
     * a future cleanup pass — flagged here, not fixed in this task.
     *
     * account_id is nullOnDelete() — same reasoning as orders.account_id
     * / operational_sales_clients.account_id: a guest redemption has
     * accountId: null and never counts toward usage_limit_per_customer.
     */
    public function up(): void
    {
        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('promotion_id')
                ->constrained('promotions', indexName: 'promo_redemptions_promotion_id_foreign')
                ->restrictOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders', indexName: 'promo_redemptions_order_id_foreign')
                ->restrictOnDelete();

            $table->foreignId('account_id')->nullable()
                ->constrained('accounts', indexName: 'promo_redemptions_account_id_foreign')
                ->nullOnDelete();

            $table->timestamp('redeemed_at');

            $table->timestamps();

            // Hot path for countForPromotion()/countForPromotionAndAccount().
            $table->index('promotion_id', 'promo_redemptions_promotion_id_index');
            $table->index(['promotion_id', 'account_id'], 'promo_redemptions_promotion_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
    }
};
