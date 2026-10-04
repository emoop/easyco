<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R2a (shipping-domain-design.md §7.2.16): cancelling an OWED refund
     * records when, why and by whom. Nullable columns, set only by the OWED ->
     * CANCELLED transition, so existing rows are untouched.
     *
     * CHECK pay_refunds_cancel_check (MySQL/MariaDB only, the same supportsCheck()
     * pattern as the R1a constraints): the date and the reason are set together
     * or not at all, and only a CANCELLED refund carries them. (A refund may be
     * CANCELLED without them — a state written before this migration or by a path
     * that has no staff member — so the reverse is not required.)
     */
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->string('cancelled_by')->nullable();
        });

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT pay_refunds_cancel_check CHECK (((cancelled_at IS NULL) = (cancelled_reason IS NULL)) AND (cancelled_at IS NULL OR status = 'cancelled'))");
        }
    }

    /** Refuses while a refund carries a cancellation record: dropping the columns would destroy it. */
    public function down(): void
    {
        $used = DB::table('payment_refunds')->whereNotNull('cancelled_at')->orderBy('id')->pluck('id');

        if ($used->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'payment_refunds.id %s carries a cancellation record; rolling back would destroy it. Nothing was changed.',
                $used->take(20)->implode(', '),
            ));
        }

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE payment_refunds DROP CONSTRAINT pay_refunds_cancel_check');
        }

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by', 'cancelled_reason', 'cancelled_at']);
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
