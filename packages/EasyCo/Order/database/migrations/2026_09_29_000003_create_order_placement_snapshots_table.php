<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * order-editing-design.md §2.1 — copied verbatim. One row per order,
     * written once by CheckoutOrchestrator inside its own placement
     * transaction, never updated: what a confirmation email told the
     * customer, preserved alongside orders' own current-state columns
     * (which reviseTotals()/reviseDelivery(), stage 2, will start
     * overwriting).
     *
     * No domain entity, no repository — the same "plain infrastructure, a
     * factual record" posture app/Models/OrderEventModel.php already takes
     * for order_events, applied here to a snapshot instead of a log.
     *
     * order_id is unique (one row per order, ever) and restrictOnDelete()
     * (same posture every other order-history table already takes — see
     * orders' own migration). Money fields are minor+currency pairs per
     * field (unlike orders itself, which shares one order-level currency
     * column across all three) — copied from the design doc's own
     * Blueprint as specified, not orders' own convention.
     */
    public function up(): void
    {
        Schema::create('order_placement_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()
                ->constrained('orders', indexName: 'ops_order_id_foreign')
                ->restrictOnDelete();
            $table->unsignedBigInteger('subtotal_minor');
            $table->string('subtotal_currency', 3);
            $table->unsignedBigInteger('discount_minor');
            $table->string('discount_currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->string('total_currency', 3);
            $table->string('applied_promotion_code')->nullable();
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
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_placement_snapshots');
    }
};
