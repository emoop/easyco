<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent read/write model for catalog_variations — see
 * 2026_08_23_000006_create_catalog_variations_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping only;
 * the domain invariants (signature/assignments consistency, etc.) live in
 * EasyCo\Catalog\Variation.
 */
class VariationModel extends Model
{
    use SoftDeletes;

    protected $table = 'catalog_variations';

    protected $fillable = [
        'product_id',
        'type',
        'status',
        'attribute_signature',
        'sku',
        'barcode',
        'is_visible',
        'is_purchasable',
        'short_description',
        'shipping_class',
        'weight_grams',
        'length_mm',
        'width_mm',
        'height_mm',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'is_purchasable' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }
}
