<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use DateTimeImmutable;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\PriceList;

/**
 * Real PriceRangeResolver — see that contract's own docblock for the
 * omission rule, the "Regular Prices"-missing-returns-[] rule, and the
 * boundedness contract this class exists to satisfy.
 *
 * HOW BOUNDEDNESS IS ACHIEVED, step by step:
 * 1. Contexts are grouped by their distinct `at` value (a context with no
 *    `at` uses `now`, shared across the whole call) — PriceListResolutionEngine::
 *    preloadForBatch() runs ONCE PER DISTINCT `at`, not once per context.
 * 2. Within each `at` group, findWinningList() runs per context, but
 *    PURELY IN MEMORY against that group's already-preloaded
 *    CandidateLists — zero queries.
 * 3. The "Regular Prices" item lookup is primed ONCE for the WHOLE group
 *    (PriceListResolutionEngine::primeItemCache(), itself at most 2
 *    queries regardless of group size).
 * 4. Every OTHER distinct FIXED_ITEMS-mode winning list actually matched
 *    within the group is primed once, for exactly the subset of contexts
 *    that matched it — at most 2 more queries per distinct such list.
 *    A PERCENTAGE_OFF_REGULAR winning list needs no item lookup at all
 *    (buildQuote() never reads one for that mode), so it is never primed.
 * 5. buildQuote() then runs per context, reading exclusively from the
 *    now-fully-primed cache — zero further queries.
 *
 * Total queries: 1 (system list lookup) + 2 per distinct `at` (candidate
 * lists + their scopes) + 2 per distinct `at` (regular item priming) + up
 * to 2 per distinct FIXED_ITEMS winning list actually matched. Never a
 * function of contexts/products/variations count — proven by a real
 * DB::listen() measurement (N=1 vs N=50 contexts, equal counts) in
 * EloquentPriceRangeResolverTest.
 */
final class EloquentPriceRangeResolver implements PriceRangeResolver
{
    private const REGULAR_PRICES_LIST_NAME = 'Regular Prices';

    public function __construct(
        private readonly PriceListRepository $priceListRepository,
        private readonly PriceListResolutionEngine $engine,
    ) {
    }

    public function resolveQuotes(array $contexts): array
    {
        if ($contexts === []) {
            return [];
        }

        $regularList = $this->priceListRepository->findSystemListByName(self::REGULAR_PRICES_LIST_NAME);

        // D3: a missing system list means nothing is configured at all —
        // an empty result set, not PriceResolver::resolve()'s
        // RuntimeException (that fail-loud signal still exists there).
        if ($regularList === null) {
            return [];
        }

        $now = new DateTimeImmutable();
        $groups = $this->groupContextsByAt($contexts, $now);

        $results = [];

        foreach ($groups as $group) {
            foreach ($this->resolveGroup($regularList, $group['at'], $group['contexts']) as $priceableId => $quote) {
                $results[$priceableId] = $quote;
            }
        }

        return $results;
    }

    /**
     * @param array<string, PriceContext> $contextsForAt keyed by priceableId
     * @return array<string, \EasyCo\Pricing\Contracts\PriceQuote>
     */
    private function resolveGroup(PriceList $regularList, DateTimeImmutable $at, array $contextsForAt): array
    {
        $candidates = $this->engine->preloadForBatch($at);

        $winningListByPriceableId = [];
        foreach ($contextsForAt as $priceableId => $context) {
            $winningListByPriceableId[$priceableId] = $this->engine->findWinningList($candidates, $context);
        }

        $regularTargets = [];
        foreach ($contextsForAt as $priceableId => $context) {
            $regularTargets[$priceableId] = ['productId' => $context->productId, 'quantity' => $context->quantity];
        }
        $this->engine->primeItemCache($regularList, $regularTargets);

        [$targetsByWinningListId, $winningListsById] = $this->groupFixedItemTargetsByWinningList(
            $regularList,
            $contextsForAt,
            $winningListByPriceableId
        );

        foreach ($targetsByWinningListId as $winningListId => $targets) {
            $this->engine->primeItemCache($winningListsById[$winningListId], $targets);
        }

        $results = [];
        foreach ($contextsForAt as $priceableId => $context) {
            $quote = $this->engine->buildQuote($regularList, $winningListByPriceableId[$priceableId], $context);

            if ($quote !== null) {
                $results[$priceableId] = $quote;
            }
        }

        return $results;
    }

    /**
     * @param array<string, PriceContext> $contextsForAt keyed by priceableId
     * @param array<string, ?PriceList> $winningListByPriceableId keyed by priceableId
     * @return array{0: array<string, array<string, array{productId: ?string, quantity: int}>>, 1: array<string, PriceList>}
     */
    private function groupFixedItemTargetsByWinningList(PriceList $regularList, array $contextsForAt, array $winningListByPriceableId): array
    {
        $targetsByWinningListId = [];
        $winningListsById = [];

        foreach ($contextsForAt as $priceableId => $context) {
            $winningList = $winningListByPriceableId[$priceableId];

            if ($winningList === null || $winningList->id() === $regularList->id()) {
                continue;
            }

            if ($winningList->mode() !== PriceListMode::FIXED_ITEMS) {
                // PERCENTAGE_OFF_REGULAR needs no item lookup at all —
                // buildQuote() computes it purely from the regular Price
                // already primed above.
                continue;
            }

            $winningListsById[$winningList->id()] = $winningList;
            $targetsByWinningListId[$winningList->id()][$priceableId] = [
                'productId' => $context->productId,
                'quantity' => $context->quantity,
            ];
        }

        return [$targetsByWinningListId, $winningListsById];
    }

    /**
     * @param PriceContext[] $contexts
     * @return array<string, array{at: DateTimeImmutable, contexts: array<string, PriceContext>}>
     */
    private function groupContextsByAt(array $contexts, DateTimeImmutable $now): array
    {
        $groups = [];

        foreach ($contexts as $context) {
            $at = $context->at ?? $now;
            $key = $at->format('Y-m-d\TH:i:s.u');

            $groups[$key]['at'] ??= $at;
            $groups[$key]['contexts'][$context->priceableId] = $context;
        }

        return $groups;
    }
}
