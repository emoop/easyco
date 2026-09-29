<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * order-lifecycle-design.md §7.4, R11 — a redemption is released only
     * when its order becomes CANCELLED. A nullable timestamp, the same
     * shape payments.confirmed_at/voided_at already use for "a fact that
     * changes what a later count means, never an edit of the placement
     * fact": NULL means still counting, set means released. No separate
     * order_events row is needed for this (the cancellation's own event
     * already records that it happened) and no other column changes
     * meaning — the existing count queries simply gain a
     * whereNull('released_at') condition.
     */
    public function up(): void
    {
        Schema::table('promotion_redemptions', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('redeemed_at');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_redemptions', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });
    }
};
