<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent write model for `order_invoice_details` (order-context-design.md section 2.2; the migration
 * 2026_10_18_000002_create_order_invoice_details_table.php is the authoritative column list). The row's EXISTENCE is the invoice request,
 * written by checkout inside the placement transaction (App\Services\OrderContextWriter) and never updated afterwards: a snapshot of the
 * billing block the customer typed, never a document, a number or a tax line.
 */
class OrderInvoiceDetailsModel extends Model
{
    protected $table = 'order_invoice_details';

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
    ];
}
