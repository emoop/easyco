<?php

namespace EasyCo\Promotions\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent read/write model for `promotions` — see
 * 2026_09_01_000001_create_promotions_table.php for the authoritative
 * column list. This is an infrastructure-layer mapping only; the
 * domain invariants live in EasyCo\Promotions\Promotion.
 *
 * scopes() is a plain infrastructure convenience relation — it does
 * not change the two-repository split (PromotionRepository never
 * touches promotion_scopes directly, same Variant B posture Pricing's
 * PriceList/PriceListScope split uses).
 */
class PromotionModel extends Model
{
    protected $table = 'promotions';

    protected $fillable = [
        'code',
        'discount_type',
        'discount_percentage_basis_points',
        'discount_amount_minor',
        'discount_amount_currency',
        'individual_use_only',
        'exclude_sale_items',
        'minimum_spend_amount_minor',
        'minimum_spend_amount_currency',
        'maximum_spend_amount_minor',
        'maximum_spend_amount_currency',
        'new_customers_only',
        'usage_limit_total',
        'usage_limit_per_customer',
        'usage_limit_items',
        'valid_from',
        'valid_until',
        'status',
    ];

    protected $casts = [
        'individual_use_only' => 'boolean',
        'exclude_sale_items' => 'boolean',
        'new_customers_only' => 'boolean',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public function scopes(): HasMany
    {
        return $this->hasMany(PromotionScopeModel::class, 'promotion_id');
    }
}
