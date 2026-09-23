<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\ProductCategory;

/**
 * save()/remove(), mirroring EasyCo\Media\Contracts\ProductMediaRepository's
 * own naming exactly. No countByProductId() — that exists on
 * ProductMediaRepository specifically for the not-yet-implemented
 * max-photos guard; there is no equivalent limit concept for category
 * assignment, so no method is added here that nothing would call.
 */
interface ProductCategoryRepository
{
    /** Insert or update. */
    public function save(ProductCategory $productCategory): void;

    public function remove(string $id): void;

    /** @return ProductCategory[] */
    public function findByProductId(string $productId): array;

    /**
     * The set-based sibling of findByProductId() — one whereIn query for
     * every category assignment across several products at once (e.g.
     * App\Services\CatalogScopeResolver::forVariations()), grouped by the
     * caller per productId. Never one findByProductId() call per product.
     *
     * @param string[] $productIds
     * @return ProductCategory[]
     */
    public function findByProductIds(array $productIds): array;
}
