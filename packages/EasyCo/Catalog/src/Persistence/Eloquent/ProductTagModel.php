<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_product_tags.
 * Infrastructure-layer mapping only; the domain invariants live in
 * EasyCo\Catalog\ProductTag.
 */
class ProductTagModel extends Model
{
    protected $table = 'catalog_product_tags';

    // The migration never added timestamps() to this table.
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'tag_id',
    ];
}
