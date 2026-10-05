<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `payments` — see
 * 2026_09_04_000001_create_payments_table.php for the authoritative
 * column list, including the DB-generated captured_order_id column
 * (never written to directly; not in $fillable) and
 * 2026_09_28_000003_add_confirmed_at_and_settled_order_id_to_payments_table.php
 * for the second generated column, settled_order_id (same rule: generated,
 * never filled, never written, never mapped — MySQL computes it from
 * `status` and `confirmed_at`, and it exists only so its unique index can
 * make "at most one settled payment per order" a database guarantee).
 * This is an infrastructure-layer mapping only; the domain invariants live
 * in EasyCo\Payment\Payment.
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
        'attempted_at',
        'confirmed_at',
        'voided_at',
        'settled_amount_minor',
        'settlement_reason',
    ];

    protected $casts = [
        'attempted_at' => 'immutable_datetime',
        'confirmed_at' => 'immutable_datetime',
        'voided_at' => 'immutable_datetime',
    ];
}
