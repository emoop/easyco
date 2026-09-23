<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\ProductTag;

/**
 * save()/remove(), mirroring EasyCo\Media\Contracts\ProductMediaRepository's
 * own naming exactly. No countByProductId() — that exists on
 * ProductMediaRepository specifically for the not-yet-implemented
 * max-photos guard; there is no equivalent limit concept for tag
 * assignment, so no method is added here that nothing would call.
 */
interface ProductTagRepository
{
    /** Insert or update. */
    public function save(ProductTag $productTag): void;

    public function remove(string $id): void;

    /** @return ProductTag[] */
    public function findByProductId(string $productId): array;

    /**
     * The set-based sibling of findByProductId() — one whereIn query for
     * every tag assignment across several products at once (e.g.
     * App\Services\CatalogScopeResolver::forVariations()), grouped by the
     * caller per productId. Never one findByProductId() call per product.
     *
     * @param string[] $productIds
     * @return ProductTag[]
     */
    public function findByProductIds(array $productIds): array;
}
