<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent read/write model for pricing_price_list_scopes — see
 * 2026_08_28_000002_create_pricing_price_list_scopes_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Pricing\PriceListScope.
 */
class PriceListScopeModel extends Model
{
    protected $table = 'pricing_price_list_scopes';

    protected $fillable = [
        'price_list_id',
        'scope_type',
        'scope_reference_id',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceListModel::class, 'price_list_id');
    }
}
