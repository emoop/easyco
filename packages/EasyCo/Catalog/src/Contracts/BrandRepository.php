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

    /**
     * Plain infrastructure-level delete — no business invariant of its
     * own beyond what countProductsUsing() already exists to let a
     * caller check first (admin-panel-design.md's Part B "delete,
     * safety-gated" work). Brand has no domain-layer delete() method
     * (nothing to protect at that layer — a Brand has no lifecycle),
     * so this is the repository's own operation: findOrFail()->delete(),
     * mirroring save()'s own fail-loud posture for an id that should
     * exist — throws ModelNotFoundException if $id doesn't, rather
     * than silently no-op-ing.
     */
    public function delete(string $id): void;
}
