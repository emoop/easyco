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
}
