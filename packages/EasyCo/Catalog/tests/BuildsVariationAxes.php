<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\VariationAxis;

/**
 * Builds VariationAxis fixtures for tests without needing a real
 * persistence layer — mirrors how an application service would assemble
 * one from already-persisted AttributeDefinition/AttributeValue rows.
 */
trait BuildsVariationAxes
{
    private function selectAttribute(string $id, string $code): AttributeDefinition
    {
        return new AttributeDefinition(id: $id, code: $code, name: ucfirst($code), type: AttributeType::SELECT);
    }

    private function attributeValue(string $id, string $definitionId, ?string $value = null): AttributeValue
    {
        return new AttributeValue(id: $id, attributeDefinitionId: $definitionId, value: $value ?? "value-{$id}");
    }

    /** @param string[] $valueIds */
    private function axis(string $definitionId, string $code, array $valueIds): VariationAxis
    {
        $definition = $this->selectAttribute($definitionId, $code);
        $values = array_map(
            fn (string $valueId) => $this->attributeValue($valueId, $definitionId),
            $valueIds
        );

        return new VariationAxis($definition, $values);
    }
}
