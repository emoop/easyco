<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_measures — see 2026_11_14_000001_create_catalog_measurement_tables.php for the authoritative column list. Infrastructure mapping only; the rules live in
 * EasyCo\Catalog\Measure.
 */
class MeasureModel extends Model
{
    protected $table = 'catalog_measures';

    protected $fillable = [
        'name',
        'unit',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];
}
