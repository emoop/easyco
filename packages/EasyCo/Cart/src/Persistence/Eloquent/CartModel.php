<?php

namespace EasyCo\Cart\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartModel extends Model
{
    protected $table = 'carts';

    protected $fillable = ['account_id', 'session_token', 'expires_at', 'applied_promotion_code'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CartLineModel::class, 'cart_id');
    }
}
