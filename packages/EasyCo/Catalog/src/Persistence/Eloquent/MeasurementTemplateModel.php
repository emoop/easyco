<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_measurement_templates — see 2026_11_14_000001_create_catalog_measurement_tables.php. The ordered measure list lives in
 * catalog_measurement_template_measures and is read/written by EloquentMeasurementTemplateRepository directly. Infrastructure mapping only.
 */
class MeasurementTemplateModel extends Model
{
    protected $table = 'catalog_measurement_templates';

    protected $fillable = [
        'name',
        'sort',
        'is_active',
        'kind',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];
}
