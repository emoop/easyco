<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_collections — see
 * 2026_09_14_000002_create_catalog_collections_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
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
