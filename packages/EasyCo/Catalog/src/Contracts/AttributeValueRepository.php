<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\AttributeValue;

/**
 * Persistence contract for AttributeValue — the enumerable values
 * belonging to a SELECT/MULTISELECT AttributeDefinition (e.g. "Black"
 * under "Color"). Mirrors AttributeDefinitionRepository's shape; values
 * are owned by their definition (catalog_attribute_values.attribute_definition_id,
 * cascade-deleted with it) but are not an aggregate the way Product is,
 * so there is no single-transaction concern here either.
 */
interface AttributeValueRepository
{
    public function save(AttributeValue $value): void;

    public function findById(string $id): ?AttributeValue;

    /** @return AttributeValue[] */
    public function findByAttributeDefinitionId(string $attributeDefinitionId): array;
}
