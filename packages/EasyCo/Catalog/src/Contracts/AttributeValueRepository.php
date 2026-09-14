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

    /**
     * Independent descriptive-vs-axis usage counts for this
     * AttributeValue — catalog-domain-design.md §3.13's "is this still
     * in use, and how" check.
     *
     * descriptive: catalog_product_attributes rows referencing this
     * value by attribute_value_id (always is_variation_axis=false rows
     * per that table's own docblock — this FK is never populated any
     * other way) — a plain count(), no distinct needed for the same
     * unique(product_id, attribute_definition_id) reason
     * AttributeDefinitionRepository's own count relies on.
     *
     * axis: DISTINCT product_id via
     * catalog_variation_attribute_values -> catalog_variations —
     * distinct IS required here, unlike every other count in this
     * family: multiple Variations of the same Product can share the
     * same axis value choice (e.g. two SKUs both "Color: Red" at
     * different sizes), and must count as one product, not two.
     *
     * @return array{descriptive: int, axis: int}
     */
    public function countProductsUsing(string $valueId): array;
}
