<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resolves checkout-domain-design.md §8.1's Account<->Client link.
     *
     * nullOnDelete(), same reasoning as orders.account_id
     * (checkout-domain-design.md §3/its own migration docblock): a
     * Client is real historical identity (it owns real SaleLines) and
     * must survive its linked Account being deleted, simply losing the
     * link — never cascade-deleted, unlike a disposable row such as
     * addresses.account_id.
     *
     * unique() — one Account maps to at most one Client. §8.1's
     * find-or-create-on-first-checkout logic (ClientRepository::
     * findByAccountId()) depends on this: without it, "find the
     * Client for this account" would not even be a well-formed
     * question.
     */
    public function up(): void
    {
        Schema::table('operational_sales_clients', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()
                ->after('id')
                ->constrained('accounts', indexName: 'os_clients_account_id_foreign')
                ->nullOnDelete();
        });

        // Explicit short name, not the unique()-chained default naming —
        // same "name it explicitly rather than trust the margin"
        // convention as os_installment_plans_active_client_id_unique.
        Schema::table('operational_sales_clients', function (Blueprint $table) {
            $table->unique('account_id', 'os_clients_account_id_unique');
        });
    }

    public function down(): void
    {
        // The FK must be dropped BEFORE the unique index: MySQL requires
        // a supporting index for every FK, and account_id has no other
        // index besides this UNIQUE one — same ordering lesson already
        // learned (and documented) on
        // 2026_08_25_000005_add_active_client_id_to_operational_sales_installment_plans_table.php.
        Schema::table('operational_sales_clients', function (Blueprint $table) {
            $table->dropForeign('os_clients_account_id_foreign');
        });

        Schema::table('operational_sales_clients', function (Blueprint $table) {
            $table->dropUnique('os_clients_account_id_unique');
            $table->dropColumn('account_id');
        });
    }
};
