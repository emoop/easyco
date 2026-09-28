<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * confirmed_at, and the second stored generated column that makes a money
 * fact DB-enforced — order-lifecycle-design.md §4.4 (its §10 stage 5), the
 * counterpart of payment-domain-design.md §5.1's captured_order_id.
 *
 * NO EXISTING MIGRATION IS EDITED, and no existing constraint is touched:
 * confirmed_at arrives as a second, separately named fact on the row the
 * adapter already answered, and the create-table migration's own
 * captured_order_id/pay_captured_order_unique pair is left exactly as it
 * shipped (that document's §4.4 "rejected alternative": widening the old
 * index's meaning via ->change() on a stored generated column would express
 * the same rule with one index, and nothing in this codebase has ever done
 * that).
 *
 * confirmed_at RECORDS WHEN THE MERCHANT SAW THE MONEY ARRIVE (that
 * document's §4.1) — not when an adapter answered, which is what
 * attempted_at next door records. It is written once, by Payment::confirm(),
 * and never cleared: §11 item 6's "no un-confirm" is a standing commitment,
 * not an oversight. A confirmation never moves `status` (§4.2), which is
 * exactly why the offline half of "the money is held" needs its own column
 * here and its own constraint below.
 *
 * settled_order_id is a STORED generated column computed as
 * CASE WHEN status = 'captured' OR confirmed_at IS NOT NULL THEN order_id
 * ELSE NULL END, with a UNIQUE index on it — the genuinely DB-enforced half
 * of "at most one settled Payment per order", covering the offline
 * confirmation §4.2 deliberately kept out of captured_order_id. MySQL
 * treats multiple NULLs in a unique index as non-conflicting, so any number
 * of pending/failed attempts (and every voided row — a void is only
 * possible on an unanswered-for-money attempt, see Payment::void()) keeps
 * contributing NULL and never collides with anything; only rows that really
 * represent money in hand compete for uniqueness on order_id.
 *
 * Added in a SEPARATE Schema::table() call, after the base column exists in
 * the same migration — the pattern 2026_09_04_000001_create_payments_table
 * established for captured_order_id, and the only order MySQL accepts a
 * generated column over a column added in the same statement.
 *
 * Existing rows get NULL in both, which is honest: for any Payment written
 * before this change, no merchant ever recorded the money as received, and
 * the generated column then computes NULL too — so no row changes state and
 * no index can conflict.
 *
 * Explicit short index name (prefix "pay_", CLAUDE.md rule 5): MySQL/MariaDB
 * identifiers stop at 64 characters, and pay_settled_order_unique is 24.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('attempted_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('settled_order_id')
                ->storedAs("CASE WHEN status = 'captured' OR confirmed_at IS NOT NULL THEN order_id ELSE NULL END")
                ->unique('pay_settled_order_unique');
        });
    }

    public function down(): void
    {
        // Reverse order, and explicitly the index before its column: the
        // generated column is what the unique index is on, and this keeps
        // down() readable as the exact mirror of up().
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('pay_settled_order_unique');
            $table->dropColumn('settled_order_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
