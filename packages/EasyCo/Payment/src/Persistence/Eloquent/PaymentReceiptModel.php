<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent write model for payment_receipts — see
 * 2026_10_08_000001_create_payment_receipts_table.php. Append-only: there is no updated_at, and
 * EloquentPaymentReceiptRepository never updates or deletes a row. `received_on` is deliberately
 * NOT cast: it is a calendar day and stays the plain 'Y-m-d' string the DATE column holds, so no
 * timezone can ever shift it.
 */
class PaymentReceiptModel extends Model
{
    public $timestamps = false;

    protected $table = 'payment_receipts';

    protected $fillable = [
        'payment_id',
        'amount_minor',
        'amount_currency',
        'received_on',
        'bank_reference',
        'supersedes_receipt_id',
        'recorded_by',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'immutable_datetime',
    ];
}
