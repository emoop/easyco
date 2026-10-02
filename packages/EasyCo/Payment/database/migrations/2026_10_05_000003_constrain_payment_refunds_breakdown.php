<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R1a, constraint step (shipping-domain-design.md §7.2.2; CLAUDE.md
     * rule 2 — an invariant the application enforces is also a real database
     * constraint wherever physically possible). Runs after the data mapping of
     * _000002, so every existing row already satisfies what is added here.
     *
     * order_id and channel become NOT NULL, and order_id gets the index the
     * order-scoped caps of R1b will read.
     *
     * The CHECKs (MySQL from 8.0.16, MariaDB from 10.2.1; other drivers keep only
     * the entity's own rules, same `supportsCheck()` pattern as
     * orders_total_formula_check):
     *  - pay_refunds_parts_check:     every breakdown part is >= 0;
     *  - pay_refunds_total_check:     the total is >= 0 and equals
     *                                 goods + shipping + adjustment - deduction;
     *  - pay_refunds_deduction_check: a deduction requires its reason;
     *  - prl_amount_check:            a refund line's amount is >= 0.
     * The domain is deliberately STRICTER than the total CHECK: PaymentRefund
     * refuses a total of 0 (a refund that moves no money is simply not created),
     * while the CHECK, as the design document words it, allows 0.
     *
     * The aggregate caps (per line, shipping, paid) span rows and cannot be a
     * CHECK; they are enforced under the order lock (R1b).
     */
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->string('order_id')->nullable(false)->change();
            $table->string('channel', 16)->nullable(false)->change();
            $table->index('order_id', 'pay_refunds_order_id_index');
        });

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT pay_refunds_parts_check CHECK (goods_minor >= 0 AND shipping_minor >= 0 AND adjustment_minor >= 0 AND deduction_minor >= 0)');
            DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT pay_refunds_total_check CHECK (amount_minor >= 0 AND amount_minor = goods_minor + shipping_minor + adjustment_minor - deduction_minor)');
            DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT pay_refunds_deduction_check CHECK (deduction_minor = 0 OR deduction_reason IS NOT NULL)');
            DB::statement('ALTER TABLE payment_refund_lines ADD CONSTRAINT prl_amount_check CHECK (amount_minor >= 0)');
        }
    }

    public function down(): void
    {
        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE payment_refund_lines DROP CONSTRAINT prl_amount_check');
            DB::statement('ALTER TABLE payment_refunds DROP CONSTRAINT pay_refunds_deduction_check');
            DB::statement('ALTER TABLE payment_refunds DROP CONSTRAINT pay_refunds_total_check');
            DB::statement('ALTER TABLE payment_refunds DROP CONSTRAINT pay_refunds_parts_check');
        }

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropIndex('pay_refunds_order_id_index');
            $table->string('order_id')->nullable()->change();
            $table->string('channel', 16)->nullable()->change();
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
