<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Refunds R1a, data step (shipping-domain-design.md §7.2.5, decided by the
     * owner 2026-10-02, Q1): every refund written before the owed/paid-out model
     * is mapped to the new shape. No schema change here.
     *
     * A GATE, NOT A GUESS (the posture of guard_no_fulfilled_orders): before it
     * writes anything it refuses, naming the ids, if a row cannot be mapped
     * without inventing a fact —
     *  - a refund whose payment does not exist (no order id to give it), or
     *  - a COMPLETED refund against a method that is not offline (only the two
     *    offline adapters ever produced refunds, so this is an anomaly).
     *
     * The mapping, for the rows that still have no order_id:
     *  - order_id := the payment's order_id; channel := cash for cash on
     *    delivery, otherwise bank (RefundChannel::defaultForMethod);
     *  - goods_minor := amount_minor (the old amount was goods only);
     *  - COMPLETED offline  -> PAID_OUT, paid_out_at = created_at, note
     *    "legacy: recorded before the owed/paid model" (the money HAD been
     *    handed back as far as anyone then knew);
     *  - PENDING            -> REQUESTED (none exist: no online adapter);
     *  - FAILED stays FAILED.
     */
    private const OFFLINE_METHODS = ['cash_on_delivery', 'bank_transfer'];

    private const LEGACY_NOTE = 'legacy: recorded before the owed/paid model';

    public function up(): void
    {
        $rows = DB::table('payment_refunds')->whereNull('order_id')->orderBy('id')->get();

        $missing = [];
        $notOffline = [];
        $plan = [];

        foreach ($rows as $row) {
            $payment = ctype_digit((string) $row->payment_id)
                ? DB::table('payments')->where('id', $row->payment_id)->first()
                : null;

            if ($payment === null) {
                $missing[] = $row->id;

                continue;
            }

            if ($row->status === 'completed' && ! in_array($payment->method, self::OFFLINE_METHODS, true)) {
                $notOffline[] = $row->id;

                continue;
            }

            $plan[] = [$row, $payment];
        }

        if ($missing !== [] || $notOffline !== []) {
            throw new RuntimeException(sprintf(
                'payment_refunds cannot be mapped to the owed/paid-out model without guessing: no payment exists for payment_refunds.id: %s; '
                .'a COMPLETED refund against a non-offline method on payment_refunds.id: %s. Nothing was changed; correct those rows by hand and re-run.',
                $missing === [] ? 'none' : implode(', ', array_slice($missing, 0, 20)),
                $notOffline === [] ? 'none' : implode(', ', array_slice($notOffline, 0, 20)),
            ));
        }

        foreach ($plan as [$row, $payment]) {
            $values = [
                'order_id' => $payment->order_id,
                'channel' => $payment->method === 'cash_on_delivery' ? 'cash' : 'bank',
                'goods_minor' => $row->amount_minor,
            ];

            if ($row->status === 'completed') {
                $values += [
                    'status' => 'paid_out',
                    'paid_out_at' => $row->created_at,
                    'paid_out_note' => self::LEGACY_NOTE,
                ];
            } elseif ($row->status === 'pending') {
                $values['status'] = 'requested';
            }

            DB::table('payment_refunds')->where('id', $row->id)->update($values);
        }
    }

    /**
     * Back to the old three states. Refuses while a CANCELLED refund exists
     * (the old shape has no such state, so it would have to be invented).
     * OWED and PAID_OUT both fold into COMPLETED, which is what the old model
     * called every offline refund.
     */
    public function down(): void
    {
        $cancelled = DB::table('payment_refunds')->where('status', 'cancelled')->orderBy('id')->pluck('id');

        if ($cancelled->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'payment_refunds.id %s is CANCELLED, a state the old model does not have. Nothing was changed.',
                $cancelled->take(20)->implode(', '),
            ));
        }

        DB::table('payment_refunds')->whereIn('status', ['owed', 'paid_out'])->update(['status' => 'completed']);
        DB::table('payment_refunds')->where('status', 'requested')->update(['status' => 'pending']);
    }
};
