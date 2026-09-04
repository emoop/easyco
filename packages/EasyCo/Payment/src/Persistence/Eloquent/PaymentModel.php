<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `payments` — see
 * 2026_09_04_000001_create_payments_table.php for the authoritative
 * column list, including the DB-generated captured_order_id column
 * (never written to directly; not in $fillable). This is an
 * infrastructure-layer mapping only; the domain invariants live in
 * EasyCo\Payment\Payment.
 */
class PaymentModel extends Model
{
    protected $table = 'payments';

    protected $fillable = [
        'order_id',
        'method',
        'amount_minor',
        'amount_currency',
        'status',
        'provider_reference',
        'failure_reason',
    ];
}
