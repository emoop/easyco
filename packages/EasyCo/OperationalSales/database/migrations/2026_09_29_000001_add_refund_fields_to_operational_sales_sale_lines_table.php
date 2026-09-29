<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * operational-sales-domain-design.md §3.4 (revised) / §3.13, stage
     * 6b-i — the REFUND ledger's own fields, on top of the existing
     * §3.13 snapshot columns this table already carries. Same
     * `<name>_minor` + `<name>_currency` pair convention every other
     * Money column here already uses (2026_09_24_000001's own docblock);
     * no reason found to deviate, so none was invented.
     *
     * ALL NULLABLE, NO BACKFILL — the same §3.13 E-D5 posture: required
     * only for a freshly-created REFUND line via the new
     * SaleLine::createRefund(); every existing row (SALE or otherwise)
     * stays NULL forever, exactly like every existing §3.13 column
     * already does for a pre-migration row.
     *
     * returned_by/returned_by_name — A REAL FK PLUS A NAME SNAPSHOT,
     * matching order_events.staff_id/staff_name and activity_log's own
     * already-established pattern in this exact project, rather than a
     * bare string. Justified the same way those two already are: a real
     * `foreignId` lets the FK constraint itself prove the referenced
     * staff row once existed (the same posture every other actor
     * reference in this codebase takes), while the name is a snapshot
     * so the REFUND line stays meaningful after the staff member is
     * renamed or deactivated — nullOnDelete, so a soft-deleted staff row
     * never takes a REFUND line's own history down with it. No
     * alternative SaleLine precedent argued against this (SaleLine has
     * no other actor-reference field to compare against — productName/
     * sku are product snapshots, not actor references), so this mirrors
     * the closest real precedent instead of inventing a new shape.
     */
    public function up(): void
    {
        Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
            $table->integer('quantity_returned')->nullable()->after('sold_attributes');

            $table->bigInteger('default_refund_amount_minor')->nullable()->after('quantity_returned');
            $table->char('default_refund_amount_currency', 3)->nullable()->after('default_refund_amount_minor');

            $table->bigInteger('actual_refund_amount_minor')->nullable()->after('default_refund_amount_currency');
            $table->char('actual_refund_amount_currency', 3)->nullable()->after('actual_refund_amount_minor');

            $table->bigInteger('display_price_at_return_minor')->nullable()->after('actual_refund_amount_currency');
            $table->char('display_price_at_return_currency', 3)->nullable()->after('display_price_at_return_minor');

            $table->foreignId('returned_by')->nullable()->after('display_price_at_return_currency')
                ->constrained('staff', indexName: 'sl_returned_by_foreign')
                ->nullOnDelete();
            $table->string('returned_by_name')->nullable()->after('returned_by');

            $table->text('return_reason')->nullable()->after('returned_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
            $table->dropForeign('sl_returned_by_foreign');

            $table->dropColumn([
                'quantity_returned',
                'default_refund_amount_minor',
                'default_refund_amount_currency',
                'actual_refund_amount_minor',
                'actual_refund_amount_currency',
                'display_price_at_return_minor',
                'display_price_at_return_currency',
                'returned_by',
                'returned_by_name',
                'return_reason',
            ]);
        });
    }
};
