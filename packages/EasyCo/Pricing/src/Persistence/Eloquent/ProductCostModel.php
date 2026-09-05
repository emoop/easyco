<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for pricing_product_costs — see
 * 2026_09_06_000001_create_pricing_product_costs_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Pricing\ProductCost.
 */
class ProductCostModel extends Model
{
    protected $table = 'pricing_product_costs';

    protected $fillable = [
        'priceable_id',
        'cost_amount_minor',
        'cost_currency',
    ];
}
