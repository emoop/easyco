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
}
