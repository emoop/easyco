<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `payment_refunds` — see
 * 2026_09_04_000002_create_payment_refunds_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Payment\PaymentRefund.
 */
class PaymentRefundModel extends Model
{
    protected $table = 'payment_refunds';

    protected $fillable = [
        'payment_id',
        'amount_minor',
        'amount_currency',
        'reason',
        'refunded_by',
        'status',
        'failure_reason',
    ];
}
