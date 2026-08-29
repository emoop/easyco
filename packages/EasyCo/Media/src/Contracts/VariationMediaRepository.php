<?php

namespace EasyCo\Media\Contracts;

use EasyCo\Media\VariationMedia;

/**
 * save()/remove(), not attach()/detach() — same reasoning as
 * ProductMediaRepository's own docblock: a VariationMedia row has no
 * side effect on a parent aggregate, so the structural precedent is
 * EasyCo\Pricing\Contracts\PriceListItemRepository, not
 * PriceListScopeRepository.
 */
interface VariationMediaRepository
{
    /** Insert or update. */
    public function save(VariationMedia $variationMedia): void;

    public function remove(string $id): void;

    /**
     * @return VariationMedia[] Ordered by sort_order ASC — required for
     *   the "sort_order = 0 is the primary photo" convention (§8) to be
     *   usable by a caller at all.
     */
    public function findByVariationId(string $variationId): array;

    /**
     * A direct count query, not findByVariationId()'s full hydration —
     * added now for the future max-media-per-variation guard (not yet
     * implemented) so the contract doesn't need to widen again later.
     */
    public function countByVariationId(string $variationId): int;
}
