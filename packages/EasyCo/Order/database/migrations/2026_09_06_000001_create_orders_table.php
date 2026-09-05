<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index/FK names (prefix "ord_"), same convention as
     * every other migration in this project — see those migrations'
     * docblocks for the MySQL 64-character identifier limit lesson
     * (CLAUDE.md rule 5).
     *
     * client_id/transaction_id are restrictOnDelete() — an Order is real
     * financial/order history and must never silently vanish because a
     * Client or Transaction row was deleted (contrast
     * operational_sales_sale_lines' own restrictOnDelete() toward the
     * same two tables, for the identical reason).
     *
     * account_id is nullOnDelete() — DELIBERATELY DIFFERENT from
     * addresses.account_id's own cascadeOnDelete() choice for the same
     * column name. An address book is disposable working data (deleting
     * the account should take its saved addresses with it); order
     * history is not — an Order must survive its Account being deleted,
     * simply losing the (already-denormalized, §3) account_id link. This
     * is not a copy-paste mistake from Address's migration; it is a
     * deliberate, different choice for a different kind of column.
     *
     * address_id is nullOnDelete() and purely informational/traceability
     * (design doc §3) — the embedded snapshot columns below are
     * authoritative for display; this FK is never re-read to render the
     * order, so losing the link on the source Address's deletion costs
     * nothing.
     *
     * subtotal_minor/discount_minor/total_minor are signed bigInteger,
     * confirmed to match operational_sales_sale_lines.amount_minor's own
     * real column type (`bigint`) via a real SHOW CREATE TABLE before
     * writing this migration, not assumed. Unlike sale_lines (a separate
     * currency column per amount field), all three share the single
     * order-level `currency` column below — design doc §3 groups them
     * under one currency, since an order has exactly one currency, not
     * one per amount.
     *
     * delivery_type/recipient_name/phone/country/city/postal_code/
     * address_line_1/address_line_2/carrier_code/pickup_point_reference/
     * settlement are an intentional structural duplication of
     * addresses' own column list (same names, same types, same
     * nullability) — design doc §4: the embedded snapshot is Order's own
     * data, not a foreign read into Address.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')
                ->constrained('operational_sales_clients', indexName: 'ord_client_id_foreign')
                ->restrictOnDelete();

            $table->foreignId('transaction_id')
                ->constrained('operational_sales_transactions', indexName: 'ord_transaction_id_foreign')
                ->restrictOnDelete();

            $table->foreignId('account_id')->nullable()
                ->constrained('accounts', indexName: 'ord_account_id_foreign')
                ->nullOnDelete();

            $table->string('email');
            $table->char('currency', 3);

            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('total_minor');

            $table->string('applied_promotion_code')->nullable();

            // placed | fulfilled | cancelled — see
            // EasyCo\Order\Enums\OrderStatus. Plain string, never a
            // native DB enum, same convention as
            // addresses.delivery_type / promotions.status.
            $table->string('status');

            $table->timestamp('placed_at');

            $table->foreignId('address_id')->nullable()
                ->constrained('addresses', indexName: 'ord_address_id_foreign')
                ->nullOnDelete();

            // street_address | pickup_point — see
            // EasyCo\Order\Enums\OrderDeliveryType.
            $table->string('delivery_type');
            $table->string('recipient_name');
            $table->string('phone');
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('carrier_code')->nullable();
            $table->string('pickup_point_reference')->nullable();
            $table->string('settlement')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
