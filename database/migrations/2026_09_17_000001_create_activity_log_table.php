<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A compact, generic activity log — app/ layer, not an EasyCo\* domain
 * package, mirroring App\Services\DetachProductFromCatalogLookup's own
 * "cross-package composition lives in app/" precedent (this is a
 * factual record, not a protected business invariant).
 *
 * entity_type/entity_id (not e.g. product_id) is deliberate even though
 * only Product is wired today — a future consumer (PriceListItem
 * changes, once Phase 2's pricing UI exists) reuses this same table
 * without a schema change.
 *
 * staff_name is a snapshot, not a live join — mirrors SaleLine's own
 * just-established productName/sku precedent (operational-sales-
 * domain-design.md §3.12), so a log entry stays meaningful even if the
 * staff member is later renamed or deactivated. staff_id is nullable
 * with nullOnDelete: a soft-deleted staff row must never take its own
 * historical log entries down with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('action');
            $table->string('field')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('staff_id')->nullable()
                ->constrained('staff', indexName: 'activity_log_staff_id_foreign')
                ->nullOnDelete();
            $table->string('staff_name')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['entity_type', 'entity_id'], 'activity_log_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
