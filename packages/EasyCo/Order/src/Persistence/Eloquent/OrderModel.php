<?php

namespace EasyCo\Order\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `orders` — see
 * 2026_09_06_000001_create_orders_table.php for the authoritative column
 * list. This is an infrastructure-layer mapping only; the domain
 * invariants live in EasyCo\Order\Order.
 *
 * placed_at cast as 'immutable_datetime' (Eloquent's built-in cast) so
 * it round-trips as a real DateTimeImmutable without manual conversion —
 * confirmed to actually produce one via a real Feature test, not assumed.
 */
class OrderModel extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'client_id',
        'account_id',
        'transaction_id',
        'email',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'total_minor',
        'applied_promotion_code',
        'status',
        'placed_at',
        'address_id',
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
    ];

    protected $casts = [
        'placed_at' => 'immutable_datetime',
    ];
}
