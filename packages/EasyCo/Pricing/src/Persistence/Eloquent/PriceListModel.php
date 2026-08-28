<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent read/write model for pricing_price_lists — see
 * 2026_08_28_000001_create_pricing_price_lists_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Pricing\PriceList.
 *
 * scopes()/items() are plain infrastructure convenience relations —
 * they do not change the repository split (Variant B, three
 * independent repositories, no cascading save across aggregates).
 */
class PriceListModel extends Model
{
    protected $table = 'pricing_price_lists';

    protected $fillable = [
        'name',
        'mode',
        'percentage_basis_points',
        'priority',
        'valid_from',
        'valid_until',
        'status',
        'is_system',
        'scope_signature',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_system' => 'boolean',
    ];

    public function scopes(): HasMany
    {
        return $this->hasMany(PriceListScopeModel::class, 'price_list_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItemModel::class, 'price_list_id');
    }
}
