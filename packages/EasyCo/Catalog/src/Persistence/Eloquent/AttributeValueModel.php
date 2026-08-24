<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_attribute_values — see
 * 2026_08_23_000008_create_catalog_attribute_values_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping only;
 * the domain invariants live in EasyCo\Catalog\AttributeValue.
 */
class AttributeValueModel extends Model
{
    protected $table = 'catalog_attribute_values';

    protected $fillable = [
        'attribute_definition_id',
        'value',
        'sort_order',
    ];
}
