<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\AttributeDefinition;

/**
 * Persistence contract for the global, reusable AttributeDefinition set
 * (catalog-domain-design.md §3.3 — "Color", "Material", "Voltage", ...).
 * Definitions are not owned by any single Product, so there is no
 * aggregate-wide transaction concern here the way ProductRepository has
 * for Product+Variations.
 */
interface AttributeDefinitionRepository
{
    public function save(AttributeDefinition $definition): void;

    public function findById(string $id): ?AttributeDefinition;

    /** @return AttributeDefinition[] */
    public function all(): array;

    /**
     * Independent descriptive-vs-axis usage counts for this
     * AttributeDefinition — catalog-domain-design.md §3.13's "is this
     * still in use, and how" check. Both read catalog_product_attributes,
     * scoped by is_variation_axis, and both are plain counts (not
     * distinct): the table's own unique(product_id,
     * attribute_definition_id) constraint means at most one row per
     * product regardless of which way it's used.
     *
     * @return array{descriptive: int, axis: int}
     */
    public function countProductsUsing(string $definitionId): array;
}
