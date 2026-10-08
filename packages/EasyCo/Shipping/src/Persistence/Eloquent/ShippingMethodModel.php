<?php

namespace EasyCo\Shipping\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `shipping_methods` — infrastructure only. Its
 * class rates (`shipping_method_class_rates`) are written and read by
 * EloquentShippingMethodRepository directly, as one unit with the method.
 */
class ShippingMethodModel extends Model
{
    protected $table = 'shipping_methods';

    protected $fillable = [
        'zone_id',
        'courier',
        'delivery_type',
        'name',
        'kind',
        'sort_order',
        'is_active',
        'amount_minor',
        'free_above_minor',
        'carrier_code',
        'requires_pickup_point',
        'class_mode',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'amount_minor' => 'integer',
        'free_above_minor' => 'integer',
        'requires_pickup_point' => 'boolean',
    ];
}
