<?php

namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\PriceListScope;

/**
 * Named attach()/detach(), deliberately not save()/delete() — mirrors
 * PriceListScope's own docblock language exactly ("a scope condition is
 * attached or detached... never edited in place").
 */
interface PriceListScopeRepository
{
    public function attach(PriceListScope $scope): void;

    public function detach(string $scopeId): void;

    /** @return PriceListScope[] */
    public function findByPriceListId(string $priceListId): array;

    /**
     * The set-based sibling of findByPriceListId() above — a thin
     * whereIn query for "every scope belonging to ANY of these lists",
     * one query regardless of how many priceListIds are given. Exists
     * for PriceListResolutionEngine::preloadForBatch() (Pricing's own
     * persistence layer), which loads every candidate PriceList's scopes
     * in one shot rather than one findByPriceListId() call per
     * candidate — findByPriceListId() itself delegates to this method
     * with a one-element array, so there is exactly one implementation
     * of "load a PriceList's scopes from storage."
     *
     * @param string[] $priceListIds
     * @return PriceListScope[]
     */
    public function findByPriceListIds(array $priceListIds): array;
}
