<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * Read-only convenience for the admin panel's table/infolist
     * columns (AttributeValueResource's `attributeDefinition.name`
     * dot-notation) — an infrastructure-layer Eloquent relationship,
     * not a domain concept; EasyCo\Catalog\AttributeValue itself only
     * ever exposes attributeDefinitionId() as a plain string.
     */
    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinitionModel::class, 'attribute_definition_id');
    }
}
