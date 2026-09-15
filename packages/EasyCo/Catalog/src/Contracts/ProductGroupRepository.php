<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\ProductGroup;

/**
 * Persistence contract for the global, reusable ProductGroup set —
 * identical shape to SeasonRepository's own minimal save/findById/all.
 * ProductGroup is not owned by any single Product, so there is no
 * aggregate-wide transaction concern here.
 */
interface ProductGroupRepository
{
    public function save(ProductGroup $productGroup): void;

    public function findById(string $id): ?ProductGroup;

    /** @return ProductGroup[] */
    public function all(): array;
}
