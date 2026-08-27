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
}
