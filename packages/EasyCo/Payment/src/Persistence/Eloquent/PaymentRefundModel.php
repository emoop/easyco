<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `payment_refunds` — see
 * 2026_09_04_000002_create_payment_refunds_table.php and the 2026_10_05
 * migrations that extended it (breakdown, order link, channel, paid-out facts)
 * for the authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Payment\PaymentRefund.
 */
class PaymentRefundModel extends Model
{
    protected $table = 'payment_refunds';

    protected $fillable = [
        'payment_id',
        'order_id',
        'amount_minor',
        'amount_currency',
        'channel',
        'goods_minor',
        'shipping_minor',
        'adjustment_minor',
        'deduction_minor',
        'deduction_reason',
        'reason',
        'refunded_by',
        'status',
        'failure_reason',
        'paid_out_at',
        'paid_out_reference',
        'paid_out_note',
        'paid_out_by',
    ];

    protected $casts = [
        'paid_out_at' => 'immutable_datetime',
    ];
}
