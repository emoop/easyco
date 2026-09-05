<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Cart-side half of checkout-domain-design.md §6's idempotency
     * mechanism.
     *
     * nullOnDelete — §6's own reasoning, quoted directly: "if an Order
     * is ever deleted... the cart simply loses its link; a cart is
     * disposable working state with no historical value of its own."
     * Deliberately the OPPOSITE choice from this same table's own
     * account_id, and for a matching, opposite reason: account_id is
     * cascadeOnDelete() because a cart should not outlive the account
     * it belongs to, but order_id is nullOnDelete() because a cart CAN
     * outlive its order — once placed, the Order's own data is what
     * matters, not the (now-disposable) cart that produced it.
     *
     * unique() — one Order can only ever have claimed one Cart; this is
     * what makes "has this cart already produced an order" and "which
     * cart produced this order" both well-formed questions.
     */
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->unique()
                ->after('session_token')
                ->constrained('orders', indexName: 'carts_order_id_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // The FK must be dropped BEFORE the unique index: MySQL requires
        // a supporting index for every FK, and order_id has no other
        // index besides this UNIQUE one — same ordering lesson already
        // documented on
        // 2026_08_25_000005_add_active_client_id_to_operational_sales_installment_plans_table.php
        // and 2026_09_06_000001_add_account_id_to_operational_sales_clients_table.php.
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign('carts_order_id_foreign');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('order_id');
        });
    }
};
