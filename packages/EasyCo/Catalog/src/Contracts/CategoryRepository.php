<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Category;

/**
 * Persistence contract for the global, reusable Category set — same
 * shape as AttributeDefinitionRepository. Category is not owned by any
 * single Product, so there is no aggregate-wide transaction concern
 * here. No parent-traversal method for v1 — just the same three methods
 * every other simple Catalog lookup entity's repository has.
 */
interface CategoryRepository
{
    public function save(Category $category): void;

    public function findById(string $id): ?Category;

    /** @return Category[] */
    public function all(): array;

    /**
     * Count of DISTINCT products attached to this Category via
     * catalog_product_categories — catalog-domain-design.md §3.13's
     * "is this Category still in use" check. A plain count() is
     * already a product count here, no DISTINCT needed: the table's
     * own unique(product_id, category_id) constraint guarantees at
     * most one row per product for a given category.
     */
    public function countProductsUsing(string $categoryId): int;

    /** Plain infrastructure-level delete — see BrandRepository::delete()'s identical reasoning. */
    public function delete(string $id): void;
}
