<?php

namespace EasyCo\Order\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent write model for order_placement_snapshots — order-editing-
 * design.md §2.1. Plain infrastructure, exactly like App\Models\
 * OrderEventModel: no domain entity or repository backs this table (a
 * write-once factual record, not a protected business invariant that
 * ever changes shape).
 *
 * WRITES ONLY, AND EVER ONLY AN INSERT — CheckoutOrchestrator writes
 * exactly one row per order, inside the same placement transaction that
 * writes the Order row itself, and never again afterward.
 *
 * `$timestamps = false`: only created_at exists on this table (§2.1's own
 * "no updated_at, this row is never updated") — there is no update to
 * stamp, so Eloquent's automatic timestamp pair does not apply.
 */
class OrderPlacementSnapshotModel extends Model
{
    public $timestamps = false;

    protected $table = 'order_placement_snapshots';

    protected $fillable = [
        'order_id',
        'subtotal_minor',
        'subtotal_currency',
        'discount_minor',
        'discount_currency',
        'total_minor',
        'total_currency',
        'applied_promotion_code',
        'delivery_type',
        'recipient_name',
        'phone',
        'country',
        'city',
        'postal_code',
        'address_line_1',
        'address_line_2',
        'carrier_code',
        'pickup_point_reference',
        'settlement',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
