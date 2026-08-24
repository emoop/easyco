<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent read/write model for catalog_products — see
 * 2026_08_23_000005_create_catalog_products_table.php for the authoritative
 * column list. This is an infrastructure-layer mapping only; the domain
 * invariants live in EasyCo\Catalog\Product.
 */
class ProductModel extends Model
{
    use SoftDeletes;

    protected $table = 'catalog_products';

    protected $fillable = [
        'type',
        'name',
        'slug',
        'base_sku',
        'short_description',
        'description',
        'brand_id',
        'size_guide_id',
        'status',
        'catalog_visibility',
        'is_featured',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
    ];

    public function variations(): HasMany
    {
        return $this->hasMany(VariationModel::class, 'product_id');
    }
}
