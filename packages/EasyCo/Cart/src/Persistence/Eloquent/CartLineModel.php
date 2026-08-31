<?php

namespace EasyCo\Cart\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class CartLineModel extends Model
{
    protected $table = 'cart_lines';

    protected $fillable = [
        'cart_id',
        'variation_id',
        'quantity',
        'price_at_add_minor',
        'price_at_add_currency',
    ];
}
