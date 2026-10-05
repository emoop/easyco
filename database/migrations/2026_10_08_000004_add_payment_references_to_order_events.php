<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R4a-1 (shipping-domain-design.md §7.2.20 §2): the two references the receipt events
     * (added in R4a-2/R4a-3 — NO event type is added here) point at, the same pattern as
     * payment_refund_id: `payment_id` (the payment the event is about) and `payment_receipt_id`
     * (the receipt it records or corrects). Nullable, real FKs, restrict — an event never loses
     * what it points at. Named explicitly and short (CLAUDE.md rule 5).
     *
     * Numbered after the Payment package's own migrations (payment_receipts is created in its
     * 2026_10_08_000001): migrations run in filename order across all paths.
     */
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('payment_refund_id')
                ->constrained('payments', indexName: 'oe_payment_fk')
                ->restrictOnDelete();
            $table->foreignId('payment_receipt_id')->nullable()->after('payment_id')
                ->constrained('payment_receipts', indexName: 'oe_payment_receipt_fk')
                ->restrictOnDelete();
        });
    }

    /** Refuses while any event carries either reference: dropping the columns would destroy that record. */
    public function down(): void
    {
        $used = DB::table('order_events')->where(fn ($query) => $query->whereNotNull('payment_id')->orWhereNotNull('payment_receipt_id'))->orderBy('id')->pluck('id');

        if ($used->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'order_events.id %s carries a payment or receipt reference; rolling back would destroy it. Nothing was changed.',
                $used->take(20)->implode(', '),
            ));
        }

        Schema::table('order_events', function (Blueprint $table) {
            $table->dropForeign('oe_payment_receipt_fk');
            $table->dropForeign('oe_payment_fk');
            $table->dropColumn(['payment_receipt_id', 'payment_id']);
        });
    }
};
