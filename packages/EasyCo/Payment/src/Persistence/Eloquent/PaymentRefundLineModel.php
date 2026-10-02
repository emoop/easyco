<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** One refund's goods amount for one original sale line — see 2026_10_05_000001's `payment_refund_lines`. */
class PaymentRefundLineModel extends Model
{
    protected $table = 'payment_refund_lines';

    /** Append-only: the column default stamps created_at, and a row is never updated. */
    public $timestamps = false;

    protected $fillable = [
        'payment_refund_id',
        'sale_line_id',
        'amount_minor',
        'amount_currency',
    ];
}
