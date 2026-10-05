<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R4a-1 (shipping-domain-design.md §7.2.20 §2): the amount a payment was SETTLED for when
     * a merchant ACCEPTED a mismatch, and the reason. Written only by Payment::confirm() with an
     * accepted amount, in the same call that sets confirmed_at. NULL means "settled for exactly
     * `amount`" — every legacy row, every exact receipt, every captured payment and every cash on
     * delivery confirmation — so there is NO backfill.
     *
     * CHECKs (MySQL/MariaDB only): both or neither; positive; different from the expected amount
     * (an acceptance exists only for a real difference); only on a confirmed payment. Short
     * explicit names (CLAUDE.md rule 5).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('settled_amount_minor')->nullable()->after('voided_at');
            $table->string('settlement_reason', 255)->nullable()->after('settled_amount_minor');
        });

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT pay_settled_pair_check CHECK ((settled_amount_minor IS NULL) = (settlement_reason IS NULL))');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT pay_settled_positive_check CHECK (settled_amount_minor IS NULL OR settled_amount_minor > 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT pay_settled_differs_check CHECK (settled_amount_minor IS NULL OR settled_amount_minor <> amount_minor)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT pay_settled_confirmed_check CHECK (settled_amount_minor IS NULL OR confirmed_at IS NOT NULL)');
        }
    }

    /** Refuses while any payment carries an accepted amount: dropping the columns would destroy that decision. */
    public function down(): void
    {
        $used = DB::table('payments')->whereNotNull('settled_amount_minor')->orderBy('id')->pluck('id');

        if ($used->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'payments.id %s carries an accepted settlement amount; rolling back would destroy that decision. Nothing was changed.',
                $used->take(20)->implode(', '),
            ));
        }

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT pay_settled_confirmed_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT pay_settled_differs_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT pay_settled_positive_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT pay_settled_pair_check');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['settlement_reason', 'settled_amount_minor']);
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
