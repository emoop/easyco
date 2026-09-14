<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_seasons — see
 * 2026_09_14_000002_create_catalog_seasons_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Catalog\Season.
 */
class SeasonModel extends Model
{
    protected $table = 'catalog_seasons';

    protected $fillable = [
        'name',
        'slug',
    ];
}
