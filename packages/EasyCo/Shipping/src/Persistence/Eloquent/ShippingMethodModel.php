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
        'destination_scope',
        'class_mode',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'amount_minor' => 'integer',
        'free_above_minor' => 'integer',
    ];

    /**
     * The dropped boolean column, still readable as an attribute (shipping stage 6a): true only for a pickup-only method. Code
     * that reads `$model->requires_pickup_point` — the admin list's pickup column today — keeps working until stage 6d replaces it
     * with the scope itself. Read only: nothing writes it.
     */
    protected $appends = ['requires_pickup_point'];

    public function getRequiresPickupPointAttribute(): bool
    {
        return ($this->attributes['destination_scope'] ?? null) === 'pickup';
    }
}
