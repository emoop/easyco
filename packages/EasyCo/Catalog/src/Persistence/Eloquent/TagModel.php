<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_tags — see
 * 2026_08_23_000004_create_catalog_tags_table.php for the authoritative
 * column list. This is an infrastructure-layer mapping only; the domain
 * invariants live in EasyCo\Catalog\Tag.
 */
class TagModel extends Model
{
    protected $table = 'catalog_tags';

    protected $fillable = [
        'name',
        'slug',
    ];
}
