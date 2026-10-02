<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R1a (shipping-domain-design.md §7.2.2, §7.2.5, §7.2.8): the
     * refund RECORD gains its order link, its payout channel, its breakdown and
     * the paid-out facts, and a new table holds the per-line goods rows.
     * Three migrations, in this order, because MySQL's DDL is not transactional
     * (CLAUDE.md rule 6) and the data step must sit between the two DDL steps:
     *   _000001 (this)  adds every column NULLABLE or DEFAULT 0, and the lines table;
     *   _000002         maps the existing rows (a GATE, not a guess);
     *   _000003         makes order_id/channel NOT NULL and adds the CHECKs.
     *
     * order_id is a PLAIN string copied from the payment at creation, exactly
     * like payments.order_id (no FK: the Payment package owns no Order). The
     * caps of R1b are order-scoped, which is why it is denormalized here
     * rather than reached through a join.
     *
     * `amount_minor` stays the refund TOTAL (= goods + shipping + adjustment −
     * deduction), so the Money mapping and every caller of amount() are
     * untouched. The four parts default to 0 so an existing row is valid
     * while it waits for _000002.
     *
     * payment_refund_lines: one row per (refund, original SALE line) holding the
     * goods amount that refund pays back for that line — the rows the per-line
     * cap sums, so cancelling a refund frees exactly its own room. Both FKs are
     * real and restrictOnDelete(): a sale line with a refund against it is
     * history (CLAUDE.md rule 4), the same posture as orders.transaction_id
     * (ord_transaction_id_foreign). Names are explicit and short (rule 5).
     */
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->string('order_id')->nullable()->after('payment_id');
            $table->string('channel', 16)->nullable()->after('amount_currency');

            $table->bigInteger('goods_minor')->default(0)->after('channel');
            $table->bigInteger('shipping_minor')->default(0)->after('goods_minor');
            $table->bigInteger('adjustment_minor')->default(0)->after('shipping_minor');
            $table->bigInteger('deduction_minor')->default(0)->after('adjustment_minor');
            $table->string('deduction_reason')->nullable()->after('deduction_minor');

            $table->timestamp('paid_out_at')->nullable();
            $table->string('paid_out_reference')->nullable();
            $table->text('paid_out_note')->nullable();
            $table->string('paid_out_by')->nullable();
        });

        Schema::create('payment_refund_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_refund_id')
                ->constrained('payment_refunds', indexName: 'prl_refund_fk')
                ->restrictOnDelete();

            $table->foreignId('sale_line_id')
                ->constrained('operational_sales_sale_lines', indexName: 'prl_sale_line_fk')
                ->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_refund_id', 'sale_line_id'], 'prl_refund_sale_line_unique');
        });
    }

    /**
     * Refuses while a refund carries anything the old shape cannot hold — a
     * shipping/adjustment/deduction part, or any per-line row — because dropping
     * these columns would silently destroy that record. Nothing is changed when
     * it throws.
     */
    public function down(): void
    {
        $lines = DB::table('payment_refund_lines')->count();
        $richer = DB::table('payment_refunds')
            ->where(fn ($query) => $query->where('shipping_minor', '>', 0)->orWhere('adjustment_minor', '>', 0)->orWhere('deduction_minor', '>', 0))
            ->orderBy('id')
            ->pluck('id');

        if ($lines > 0 || $richer->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'payment_refunds holds breakdown data the old shape cannot keep (%d per-line row(s); shipping/adjustment/deduction on payment_refunds.id: %s). '
                .'Rolling back would destroy it. Nothing was changed.',
                $lines,
                $richer->isEmpty() ? 'none' : $richer->take(20)->implode(', '),
            ));
        }

        Schema::dropIfExists('payment_refund_lines');

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropColumn([
                'paid_out_by', 'paid_out_note', 'paid_out_reference', 'paid_out_at',
                'deduction_reason', 'deduction_minor', 'adjustment_minor', 'shipping_minor', 'goods_minor',
                'channel', 'order_id',
            ]);
        });
    }
};
