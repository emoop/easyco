<?php

namespace EasyCo\Media\Contracts;

use EasyCo\Media\ProductMedia;

/**
 * save()/remove(), not attach()/detach(): unlike
 * EasyCo\Pricing\Contracts\PriceListScopeRepository, a ProductMedia row
 * has no side effect on a parent aggregate (no signature to
 * recompute) — the closer structural precedent is
 * EasyCo\Pricing\Contracts\PriceListItemRepository (a mutable field
 * plus a plain insert-or-update save()), not PriceListScope. §2.1's
 * "attach media X to product id Y" language is still accurate at the
 * domain-concept level (see ProductMedia itself) — these repository
 * method names simply mirror the more precise structural precedent.
 */
interface ProductMediaRepository
{
    /** Insert or update. */
    public function save(ProductMedia $productMedia): void;

    public function remove(string $id): void;

    /**
     * @return ProductMedia[] Ordered by sort_order ASC — required for
     *   the "sort_order = 0 is the primary photo" convention (§8) to be
     *   usable by a caller at all.
     */
    public function findByProductId(string $productId): array;

    /**
     * A direct count query, not findByProductId()'s full hydration —
     * added now for the future max-media-per-product guard (not yet
     * implemented) so the contract doesn't need to widen again later.
     */
    public function countByProductId(string $productId): int;
}
