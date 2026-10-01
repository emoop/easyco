<?php

namespace EasyCo\Shipping\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `shipping_zones` — infrastructure only. The two
 * JSON columns are deliberately NOT cast: EloquentShippingZoneRepository
 * encodes them itself with JSON_UNESCAPED_UNICODE so Cyrillic is stored as
 * itself, which Eloquent's own array cast does not do.
 */
class ShippingZoneModel extends Model
{
    protected $table = 'shipping_zones';

    protected $fillable = ['name', 'sort_order', 'country_codes', 'settlement_patterns'];
}
