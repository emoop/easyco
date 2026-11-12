<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\ProductCollection;

/**
 * save()/remove(), mirroring EasyCo\Media\Contracts\ProductMediaRepository's
 * own naming exactly. No countByProductId() — that exists on
 * ProductMediaRepository specifically for the not-yet-implemented
 * max-photos guard; there is no equivalent limit concept for collection
 * assignment, so no method is added here that nothing would call.
 */
interface ProductCollectionRepository
{
    /** Insert or update. */
    public function save(ProductCollection $productCollection): void;

    public function remove(string $id): void;

    /** @return ProductCollection[] */
    public function findByProductId(string $productId): array;

    /**
     * The set-based sibling of findByProductId() — one whereIn query for
     * every collection assignment across several products at once (e.g.
     * App\Services\CatalogScopeResolver::forVariations()), grouped by the
     * caller per productId. Never one findByProductId() call per product.
     *
     * @param string[] $productIds
     * @return ProductCollection[]
     */
    public function findByProductIds(array $productIds): array;
}
