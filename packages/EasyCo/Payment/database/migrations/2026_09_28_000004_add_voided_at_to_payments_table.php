<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A separate migration adding one nullable column — the original
 * create-table migration is never edited, same convention every other
 * added column in this project follows (this file is a deliberate sibling
 * of 2026_09_10_000001_add_attempted_at_to_payments_table.php, which added
 * the same class of fact to the same table the same way).
 *
 * voided_at RECORDS WHEN THE ORDER'S REQUIREMENT SHRANK — NOT a fourth
 * PaymentStatus, and not the attempt's outcome. The customer was never told
 * a smaller amount, so what is owed changes by adding a new row, never by
 * editing the old one (order-lifecycle-design.md §7.3, and that document's
 * §11 item 19: "current payment" is one rule — the newest row by
 * attempted_at DESC, id DESC whose voided_at is NULL). PaymentStatus's own
 * docblock already argues against a "voided" status: an attempt either
 * captures or it doesn't, and PENDING stays a legitimate final answer for
 * an offline method.
 *
 * IT DELIBERATELY DOES NOT PARTICIPATE IN EITHER UNIQUE INDEX. A void is
 * only possible on a pending, not-yet-confirmed payment (Payment::void()
 * refuses a settled, failed or unanswered one), so a voided row always
 * contributes NULL to both captured_order_id and settled_order_id — the
 * generated expressions are never given a reason to look at this column.
 *
 * Existing rows get NULL, which is honest: for any Payment written before
 * this change, no obligation was ever called off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('voided_at');
        });
    }
};
