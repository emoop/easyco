<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_categories — see
 * 2026_08_23_000003_create_catalog_categories_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Catalog\Category.
 */
class CategoryModel extends Model
{
    protected $table = 'catalog_categories';

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
    ];
}
