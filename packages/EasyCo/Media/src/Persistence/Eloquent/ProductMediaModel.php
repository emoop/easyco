<?php

namespace EasyCo\Media\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_product_media. Infrastructure-
 * layer mapping only; the domain invariants live in
 * EasyCo\Media\ProductMedia.
 */
class ProductMediaModel extends Model
{
    protected $table = 'catalog_product_media';

    // The migration never added timestamps() to this table.
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'media_id',
        'sort_order',
    ];
}
