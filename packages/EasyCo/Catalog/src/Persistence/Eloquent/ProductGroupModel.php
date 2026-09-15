<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_product_groups — see
 * 2026_09_15_000001_create_catalog_product_groups_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Catalog\ProductGroup.
 */
class ProductGroupModel extends Model
{
    protected $table = 'catalog_product_groups';

    protected $fillable = [
        'code',
        'name',
    ];
}
