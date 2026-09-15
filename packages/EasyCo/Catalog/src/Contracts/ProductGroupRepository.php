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

    /**
     * Count of catalog_products rows referencing this ProductGroup via
     * product_group_id — catalog-domain-design.md §3.13's "is this X
     * still in use before letting a merchant delete/rename it away"
     * check, mirroring SeasonRepository::countProductsUsing() exactly.
     * A plain count(), not distinct: product_group_id is a single
     * column on catalog_products, one row per Product.
     */
    public function countProductsUsing(string $productGroupId): int;

    /** Plain infrastructure-level delete — see BrandRepository::delete()'s identical reasoning. */
    public function delete(string $id): void;
}
