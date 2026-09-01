<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_product_categories.
 * Infrastructure-layer mapping only; the domain invariants live in
 * EasyCo\Catalog\ProductCategory.
 */
class ProductCategoryModel extends Model
{
    protected $table = 'catalog_product_categories';

    // The migration never added timestamps() to this table.
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'category_id',
    ];
}
