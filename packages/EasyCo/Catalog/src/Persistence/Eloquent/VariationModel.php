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
        // The merchant's own display order — a persistence-only
        // merchandising concern, deliberately NOT a Variation domain
        // field (see the 2026_09_23_000001 migration's docblock). Written
        // by EloquentProductRepository::saveVariation() (append, max+1,
        // for a new row) and EloquentVariationRepository::
        // updateSortOrders() (the drag-and-drop reorder).
        'sort_order',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'is_purchasable' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }
}
