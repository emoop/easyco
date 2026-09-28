<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * order_events — the order's own always-on history (order-lifecycle-design.md
 * §6.1, §10 stage 3). This file is the authoritative column list; §6.1's own
 * table gives each column's reason, and §6 argues why the table exists at all
 * (ActivityLogger's journal is gated by a Site Setting and age-pruned; an
 * order's history may be neither).
 *
 * APP LAYER, LIKE activity_log: a factual record, not a protected business
 * invariant, so there is no domain layer and no repository behind it —
 * App\Services\OrderEventRecorder writes it, OrderAdminReader reads it back,
 * and nothing else in the codebase touches the table.
 *
 * Explicit short FK names (prefix "oe_", CLAUDE.md rule 5): MySQL/MariaDB
 * identifiers stop at 64 characters, and Laravel's own generated name
 * "order_events_transaction_id_foreign" is already 36 — the prefix keeps every
 * one of the three comfortably short, so a future column can never silently
 * truncate one.
 *
 * order_id and transaction_id are restrictOnDelete() — the posture `orders`
 * itself takes toward its own transaction_id
 * (2026_09_06_000001_create_orders_table.php:61-63), and the honest
 * alternative to a cascade that would delete history. That constraint is what
 * PROVES no order-deletion path exists (§11 item 1) rather than assuming it:
 * an order with any history cannot be deleted by any future code that tries.
 *
 * staff_id is nullOnDelete() with a staff_name snapshot beside it, copied from
 * activity_log verbatim (2026_09_17_000001_create_activity_log_table.php:18-23):
 * the entry stays meaningful after the staff member is renamed or deactivated,
 * and a deleted staff row never takes the order's history down with it. Both
 * are NULL for a console or queued caller (§6.2).
 *
 * NO created_at/updated_at, and no timestamps(): an append-only row has no
 * update to stamp and the only statement its writer ever issues is an insert.
 * occurred_at is the instant the fact happened, caller-supplied, like
 * `placed_at` — ActivityLogModel's own `$timestamps = false` precedent.
 *
 * No index beyond the FK's own: every query is
 * `WHERE order_id = ? ORDER BY occurred_at, id`, and that FK's generated index
 * already narrows to one order's handful of rows (§6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders', indexName: 'oe_order_id_foreign')
                ->restrictOnDelete();

            $table->string('type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('reason')->nullable();

            $table->foreignId('transaction_id')->nullable()
                ->constrained('operational_sales_transactions', indexName: 'oe_transaction_id_foreign')
                ->restrictOnDelete();

            $table->foreignId('staff_id')->nullable()
                ->constrained('staff', indexName: 'oe_staff_id_foreign')
                ->nullOnDelete();
            $table->string('staff_name')->nullable();

            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
