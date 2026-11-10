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

    /**
     * P6b: makes the list's scope rows EXACTLY $scopes in one transaction: the rows that are not wanted are deleted, the missing ones inserted, and the signature is recalculated ONCE at the end — so no intermediate
     * set (e.g. «no scope» between detaching the old rows and attaching the new ones) can collide with another list's UNIQUE(priority, scope_signature).
     *
     * P6b.1: with $priority the list's priority is changed in the SAME update as the signature (a move to a free priority cannot collide half-way).
     *
     * @param PriceListScope[] $scopes the wanted rows (the already attached ones are kept)
     */
    public function replaceForPriceList(string $priceListId, array $scopes, ?int $priority = null): void;

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
