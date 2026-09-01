<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Brand;

/**
 * Persistence contract for the global, reusable Brand set — same shape
 * as AttributeDefinitionRepository. Brand is not owned by any single
 * Product, so there is no aggregate-wide transaction concern here.
 */
interface BrandRepository
{
    public function save(Brand $brand): void;

    public function findById(string $id): ?Brand;

    /** @return Brand[] */
    public function all(): array;
}
