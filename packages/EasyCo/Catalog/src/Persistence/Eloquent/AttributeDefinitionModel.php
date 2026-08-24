<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_attribute_definitions — see
 * 2026_08_23_000007_create_catalog_attribute_definitions_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping only;
 * the domain invariants live in EasyCo\Catalog\AttributeDefinition.
 */
class AttributeDefinitionModel extends Model
{
    protected $table = 'catalog_attribute_definitions';

    protected $fillable = [
        'code',
        'name',
        'type',
    ];
}
