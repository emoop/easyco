<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListScope;

/**
 * One batch's worth of preloaded, in-memory ACTIVE+time-window-valid
 * PriceLists plus every one of their scopes — PriceListResolutionEngine::
 * preloadForBatch()'s own return type. Internal to this package's
 * persistence layer (not in Contracts/): nothing outside
 * PriceListResolutionEngine ever needs to construct or inspect one
 * directly.
 */
final class CandidateLists
{
    /**
     * @param PriceList[] $lists ordered priority DESC, id DESC — the
     *   same ordering findAllActiveAndValidAt() itself guarantees, which
     *   is what makes "the first scope-matching candidate in this order"
     *   correct (§4.6 steps 1-2).
     * @param array<string, PriceListScope[]> $scopesByPriceListId
     */
    public function __construct(
        public readonly array $lists,
        public readonly array $scopesByPriceListId,
    ) {
    }
}
