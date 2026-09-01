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
}
