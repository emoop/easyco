<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use Illuminate\Support\Facades\DB;

/**
 * Maps AttributeValue onto catalog_attribute_values via
 * AttributeValueModel. Mirrors EloquentAttributeDefinitionRepository's
 * id-or-new + assignId() pattern exactly; a duplicate
 * (attribute_definition_id, value) pair is a plain unique-constraint
 * violation that propagates as a QueryException, same reasoning as
 * that repository's `code` uniqueness.
 */
final class EloquentAttributeValueRepository implements AttributeValueRepository
{
    public function save(AttributeValue $value): void
    {
        $model = $value->id() !== null
            ? AttributeValueModel::findOrFail($value->id())
            : new AttributeValueModel();

        $model->attribute_definition_id = $value->attributeDefinitionId();
        $model->value = $value->value();
        $model->sort_order = $value->sortOrder();
        $model->save();

        if ($value->id() === null) {
            $value->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?AttributeValue
    {
        $model = AttributeValueModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return AttributeValue[] */
    public function findByAttributeDefinitionId(string $attributeDefinitionId): array
    {
        return AttributeValueModel::where('attribute_definition_id', $attributeDefinitionId)
            ->get()
            ->map(fn (AttributeValueModel $model) => $this->toDomain($model))
            ->all();
    }

    public function countProductsUsing(string $valueId): array
    {
        $descriptive = DB::table('catalog_product_attributes')
            ->where('attribute_value_id', $valueId)
            ->count();

        $axis = DB::table('catalog_variation_attribute_values')
            ->join('catalog_variations', 'catalog_variations.id', '=', 'catalog_variation_attribute_values.variation_id')
            ->where('catalog_variation_attribute_values.attribute_value_id', $valueId)
            ->distinct()
            ->count('catalog_variations.product_id');

        return ['descriptive' => $descriptive, 'axis' => $axis];
    }

    private function toDomain(AttributeValueModel $model): AttributeValue
    {
        return new AttributeValue(
            id: (string) $model->id,
            attributeDefinitionId: (string) $model->attribute_definition_id,
            value: $model->value,
            sortOrder: (int) $model->sort_order,
        );
    }
}
