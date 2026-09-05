<?php

namespace EasyCo\Promotions\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `promotion_redemptions` — see
 * 2026_09_06_000001_create_promotion_redemptions_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in
 * EasyCo\Promotions\PromotionRedemption.
 */
class PromotionRedemptionModel extends Model
{
    protected $table = 'promotion_redemptions';

    protected $fillable = [
        'promotion_id',
        'order_id',
        'account_id',
        'redeemed_at',
    ];

    protected $casts = [
        'redeemed_at' => 'immutable_datetime',
    ];
}
