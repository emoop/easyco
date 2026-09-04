<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index name (prefix "pay_"), same convention as
     * promotions'/pricing's own migrations — see those migrations'
     * docblocks for the MySQL 64-character identifier limit lesson
     * (CLAUDE.md rule 5).
     *
     * order_id is a PLAIN string, deliberately NOT a foreign key — the
     * Order domain doesn't exist yet (payment-domain-design.md §6); a
     * genuine forward reference, not an oversight.
     *
     * amount_minor/amount_currency mirrors
     * operational_sales_sale_lines' own Money-persistence convention
     * exactly: a signed bigint of minor units (never a float) plus a
     * 3-letter currency code — Money's own public API
     * (minorValue(): int, currency(): Currency).
     *
     * captured_order_id is added in a SEPARATE Schema::table() call
     * below, after the base table exists — see this migration's own
     * docblock on that column for why (payment-domain-design.md §5.1).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('order_id');

            // Free-form — "cash_on_delivery", "bank_transfer",
            // "card_stripe", anything — NOT a fixed enum, deliberately
            // extensible for future providers. See
            // EasyCo\Payment\Payment's own docblock.
            $table->string('method');

            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            // pending | captured | failed — see
            // EasyCo\Payment\Enums\PaymentStatus.
            $table->string('status');

            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index('order_id', 'pay_payments_order_id_index');
        });

        /**
         * captured_order_id: a STORED generated column computed as
         * CASE WHEN status = 'captured' THEN order_id ELSE NULL END,
         * with a UNIQUE index on it — the genuinely DB-enforced half of
         * payment-domain-design.md §5.1's "at most one CAPTURED
         * Payment per order" invariant. MySQL treats multiple NULLs in
         * a unique index as non-conflicting, so a PENDING/FAILED row
         * (any number of them, across retries) contributes NULL and
         * never collides with anything — only rows that are actually
         * CAPTURED ever compete for uniqueness on order_id. Laravel's
         * fluent storedAs() modifier is confirmed supported by this
         * app's MySqlGrammar (Grammars\MySqlGrammar::modifyStoredAs())
         * rather than assumed, so no raw DB::statement() fallback is
         * needed here.
         */
        Schema::table('payments', function (Blueprint $table) {
            $table->string('captured_order_id')
                ->storedAs("CASE WHEN status = 'captured' THEN order_id ELSE NULL END")
                ->unique('pay_captured_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
