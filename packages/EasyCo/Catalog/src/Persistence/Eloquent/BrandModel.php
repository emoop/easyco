<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_brands — see
 * 2026_08_23_000001_create_catalog_brands_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Catalog\Brand.
 */
class BrandModel extends Model
{
    protected $table = 'catalog_brands';

    protected $fillable = [
        'name',
        'slug',
    ];
}
