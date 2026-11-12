<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_collections — see
 * 2026_11_12_000001_create_catalog_collections_tables.php for the
 * authoritative column list (and the pivot catalog_product_collections). This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Catalog\Collection.
 */
class CollectionModel extends Model
{
    protected $table = 'catalog_collections';

    protected $fillable = [
        'name',
        'slug',
        'picture_media_asset_id',
    ];
}
