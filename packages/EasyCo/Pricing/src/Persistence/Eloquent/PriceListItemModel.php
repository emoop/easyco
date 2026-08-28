<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent read/write model for pricing_price_list_items — see
 * 2026_08_28_000003_create_pricing_price_list_items_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Pricing\PriceListItem.
 */
class PriceListItemModel extends Model
{
    protected $table = 'pricing_price_list_items';

    protected $fillable = [
        'price_list_id',
        'target_type',
        'target_id',
        'min_quantity',
        'price_amount_minor',
        'price_currency',
        'price_tax_rate_basis_points',
        'price_tax_inclusive',
    ];

    protected $casts = [
        'price_tax_inclusive' => 'boolean',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceListModel::class, 'price_list_id');
    }
}
