<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent write model for order_events — see
 * database/migrations/2026_09_28_000002_create_order_events_table.php for the
 * authoritative column list, and order-lifecycle-design.md §6 for why the table
 * exists. Plain infrastructure, exactly like App\Models\ActivityLogModel: no
 * domain layer backs this table (a factual record, not a protected business
 * invariant).
 *
 * WRITES ONLY, AND EVER ONLY AN INSERT — App\Services\OrderEventRecorder is the
 * one writer in the codebase, and §6.2 commits it to never updating or deleting
 * a row. Reads for the admin panel go through OrderAdminReader (D5's
 * single-reader rule), not through this model.
 *
 * `$timestamps = false`: an append-only row has no update to stamp, and
 * occurred_at is the caller's fact, not Laravel's bookkeeping.
 */
class OrderEventModel extends Model
{
    public $timestamps = false;

    protected $table = 'order_events';

    protected $fillable = [
        'order_id',
        'type',
        'from_status',
        'to_status',
        'reason',
        'transaction_id',
        'staff_id',
        'staff_name',
        'occurred_at',
        'operation_key',
        'operation_payload_hash',
        'payment_refund_id',
        'announced_return_on',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        // A calendar day ('Y-m-d') in the STORE timezone: no instant, so no timezone shifting.
        'announced_return_on' => 'date:Y-m-d',
    ];
}
