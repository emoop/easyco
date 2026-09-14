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

    /**
     * Count of catalog_products rows referencing this Brand via
     * brand_id — catalog-domain-design.md §3.13's "is this Brand still
     * in use before letting a merchant delete/rename it away" check. A
     * plain count(), not distinct: brand_id is a single column on
     * catalog_products, one row per Product.
     */
    public function countProductsUsing(string $brandId): int;
}
