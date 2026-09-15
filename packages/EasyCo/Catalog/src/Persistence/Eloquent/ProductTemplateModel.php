<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_product_templates — see
 * 2026_09_15_000002_create_catalog_product_templates_table.php for the
 * authoritative column list. category_ids/tag_ids are cast to 'array'
 * — plain JSON columns, no pivot table, per §3.16's own "no relational
 * integrity needed" reasoning. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Catalog\ProductTemplate.
 */
class ProductTemplateModel extends Model
{
    protected $table = 'catalog_product_templates';

    protected $fillable = [
        'name',
        'brand_id',
        'season_id',
        'product_group_id',
        'category_ids',
        'tag_ids',
    ];

    protected $casts = [
        'category_ids' => 'array',
        'tag_ids' => 'array',
    ];
}
