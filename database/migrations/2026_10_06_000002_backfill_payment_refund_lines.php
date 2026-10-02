<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Refunds R1b (shipping-domain-design.md §7.2.2): the per-line cap sums
     * `payment_refund_lines`, so every refund that paid back goods must have its
     * rows BEFORE the cap goes live. Refunds written before R1a have none; this
     * migration gives them theirs, from the REFUND sale lines of the very return
     * that produced them.
     *
     * THE LINK THAT REALLY EXISTS in the code: OrderStatusChanger writes, in one
     * transaction, a `returned` event and then a `refunded` (now also
     * `refund_owed`) event, both carrying the return's own `transaction_id`, and
     * the refund itself carries only a payment id (-> payments.order_id). So a
     * refund is matched to a return like this, per order: the order's refunds
     * that have goods and no lines, in id order, are paired one-to-one with the
     * order's `refunded` events, in id order. The pairing is accepted only if it
     * is UNAMBIGUOUS — equal counts, and each pair agreeing on the amount (the
     * sum of the return transaction's REFUND lines' actual amounts equals the
     * refund's goods) — and the rows written are exactly those REFUND lines
     * (originating SALE line -> actual amount).
     *
     * A GATE, NOT A GUESS (the posture of guard_no_fulfilled_orders): if ANY such
     * refund cannot be matched unambiguously, or its lines do not add up to its
     * goods amount, the migration writes NOTHING and aborts naming the refund
     * ids. A refund with goods 0 (a future money-only refund) needs no lines and
     * is skipped.
     */
    public function up(): void
    {
        $refunds = DB::table('payment_refunds as r')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('payment_refund_lines as l')->whereColumn('l.payment_refund_id', 'r.id'))
            ->where('r.goods_minor', '>', 0)
            ->orderBy('r.id')
            ->get(['r.id', 'r.order_id', 'r.goods_minor']);

        $unmatched = [];
        $plan = [];

        foreach ($refunds->groupBy('order_id') as $orderId => $orderRefunds) {
            $events = DB::table('order_events')
                ->where('order_id', $orderId)
                ->where('type', 'refunded')
                ->whereNotNull('transaction_id')
                ->orderBy('id')
                ->get(['id', 'transaction_id']);

            if ($events->count() !== $orderRefunds->count()) {
                foreach ($orderRefunds as $refund) {
                    $unmatched[] = $refund->id;
                }

                continue;
            }

            foreach ($orderRefunds->values() as $index => $refund) {
                $lines = DB::table('operational_sales_sale_lines')
                    ->where('transaction_id', $events[$index]->transaction_id)
                    ->where('type', 'refund')
                    ->orderBy('id')
                    ->get(['originating_sale_line_id', 'actual_refund_amount_minor', 'actual_refund_amount_currency']);

                $sum = (int) $lines->sum('actual_refund_amount_minor');

                if ($lines->isEmpty() || $sum !== (int) $refund->goods_minor || $lines->pluck('originating_sale_line_id')->duplicates()->isNotEmpty()) {
                    $unmatched[] = $refund->id;

                    continue;
                }

                $plan[$refund->id] = $lines;
            }
        }

        if ($unmatched !== []) {
            sort($unmatched);

            throw new RuntimeException(sprintf(
                'payment_refund_lines cannot be backfilled without guessing: payment_refunds.id %s has goods but no per-line rows, and its return could not be matched '
                .'unambiguously to REFUND sale lines that add up to its goods amount. Nothing was changed; resolve those refunds by hand and re-run.',
                implode(', ', array_slice($unmatched, 0, 20)).(count($unmatched) > 20 ? ', …' : ''),
            ));
        }

        foreach ($plan as $refundId => $lines) {
            foreach ($lines as $line) {
                DB::table('payment_refund_lines')->insert([
                    'payment_refund_id' => $refundId,
                    'sale_line_id' => $line->originating_sale_line_id,
                    'amount_minor' => $line->actual_refund_amount_minor,
                    'amount_currency' => $line->actual_refund_amount_currency,
                ]);
            }
        }
    }

    /** Nothing to undo that is safe to undo: the rows are the record of what each refund paid per line. */
    public function down(): void
    {
    }
};
