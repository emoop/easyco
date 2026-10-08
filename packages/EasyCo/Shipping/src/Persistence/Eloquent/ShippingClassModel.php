<?php

namespace EasyCo\Shipping\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `shipping_classes` — infrastructure only; the
 * invariants live in EasyCo\Shipping\ShippingClass. Never leaves a repository.
 */
class ShippingClassModel extends Model
{
    protected $table = 'shipping_classes';

    protected $fillable = ['code', 'name', 'description', 'is_default', 'default_marker'];
}
