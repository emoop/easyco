<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use DateTimeImmutable;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\FixedItemsPriceLookup;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;

/**
 * Internal to this package's persistence layer (not in Contracts/) — the
 * ONE real implementation of §4.6 steps 1-5 (candidate loading, AND-scope
 * matching, item-lookup fallback+tier, final-price computation), shared
 * by both EloquentPriceResolver (single-target, byte-for-byte the same
 * observable behaviour it always had) and EloquentPriceRangeResolver
 * (batched, pricing-domain-design.md §4 "Price ranges"). Neither public
 * resolver re-derives any of this math — they only call this engine.
 *
 * THE ITEM CACHE IS WHAT MAKES BATCHING BOUNDED: buildQuote() below never
 * issues a query itself — it only ever reads from $this->itemCache,
 * primed by primeItemCache(). EloquentPriceResolver's single-target path
 * relies on cachedItemPrice()'s own lazy-prime-on-miss fallback (so it
 * never has to prime anything explicitly, staying exactly as simple as
 * the old direct-forTarget() call was); EloquentPriceRangeResolver primes
 * explicitly, once per distinct (list, batch-of-targets) pair, BEFORE
 * looping buildQuote() over every context in that batch — see that
 * class's own docblock for why that is what keeps its own query count
 * from growing with the number of contexts.
 */
final class PriceListResolutionEngine
{
    /** @var array<string, array<string, ?Price>> priceListId => priceableId => resolved item Price (or null = "no item") */
    private array $itemCache = [];

    public function __construct(
        private readonly PriceListRepository $priceListRepository,
        private readonly PriceListScopeRepository $priceListScopeRepository,
        private readonly FixedItemsPriceLookup $fixedItemsPriceLookup,
    ) {
    }

    /**
     * §4.6 steps 1-2's data: every ACTIVE, time-window-valid PriceList at
     * $at (one query), plus ALL of their scopes in one whereIn query
     * (PriceListScopeRepository::findByPriceListIds()) — never one scope
     * query per candidate list, which is what made the old per-context
     * scopeMatches() loop unusable for a batch.
     */
    public function preloadForBatch(DateTimeImmutable $at): CandidateLists
    {
        $lists = $this->priceListRepository->findAllActiveAndValidAt($at);

        $listIds = array_map(static fn (PriceList $list): string => $list->id(), $lists);
        $scopes = $this->priceListScopeRepository->findByPriceListIds($listIds);

        $scopesByPriceListId = [];
        foreach ($scopes as $scope) {
            $scopesByPriceListId[$scope->priceListId()][] = $scope;
        }

        return new CandidateLists($lists, $scopesByPriceListId);
    }

    /**
     * §4.6 steps 1-2: pure, in-memory AND-scope matching against an
     * already-preloaded CandidateLists — zero queries, never a
     * per-context query. The first scope-matching candidate (in
     * CandidateLists' own priority DESC, id DESC order) wins outright.
     */
    public function findWinningList(CandidateLists $candidates, PriceContext $context): ?PriceList
    {
        foreach ($candidates->lists as $candidate) {
            if ($this->scopeMatches($candidates, $candidate, $context)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Zero scopes = universal (always matches). Otherwise AND logic, §4.1. */
    private function scopeMatches(CandidateLists $candidates, PriceList $list, PriceContext $context): bool
    {
        $scopes = $candidates->scopesByPriceListId[$list->id()] ?? [];

        if ($scopes === []) {
            return true;
        }

        foreach ($scopes as $scope) {
            $matches = match ($scope->scopeType()) {
                PriceListScopeType::CUSTOMER_GROUP => $scope->scopeReferenceId() === $context->customerGroupId,
                PriceListScopeType::CHANNEL => $scope->scopeReferenceId() === $context->channelId,
                PriceListScopeType::PRODUCT => $scope->scopeReferenceId() === $context->productId,
                PriceListScopeType::BRAND, PriceListScopeType::CATEGORY, PriceListScopeType::TAG, PriceListScopeType::ATTRIBUTE_VALUE => in_array(
                    $scope->scopeReferenceId(),
                    $context->matchingScopeReferenceIds[$scope->scopeType()->value] ?? [],
                    true
                ),
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * Primes this engine's own in-memory item cache for one PriceList
     * against a batch of targets — ONE FixedItemsPriceLookup::forTargets()
     * call (itself at most 2 queries: one VARIATION-target whereIn, one
     * PRODUCT-target whereIn), so buildQuote() below never has to issue a
     * query of its own for anything already primed. Safe to call more
     * than once for the same list — a later call only ADDS entries, it
     * never clears what is already cached.
     *
     * @param array<string, array{productId: ?string, quantity: int}> $targets keyed by priceableId
     */
    public function primeItemCache(PriceList $list, array $targets): void
    {
        if ($targets === []) {
            return;
        }

        foreach ($this->fixedItemsPriceLookup->forTargets($list, $targets) as $priceableId => $price) {
            $this->itemCache[$list->id()][$priceableId] = $price;
        }
    }

    /**
     * §4.6 steps 3-5, complete: the regular item's own fallback+tier
     * lookup, then the final-price computation (percentage-off against
     * "Regular Prices" specifically, or the winning list's own fixed
     * item with no blending — never falling through to the
     * next-highest-priority list). Returns null exactly when the regular
     * item cannot be resolved at all — the caller decides what that
     * means (EloquentPriceResolver throws PriceNotConfiguredException;
     * EloquentPriceRangeResolver omits the target, per D3).
     */
    public function buildQuote(PriceList $regularList, ?PriceList $winningList, PriceContext $context): ?PriceQuote
    {
        $regular = $this->cachedItemPrice($regularList, $context);

        if ($regular === null) {
            return null;
        }

        if ($winningList === null || $winningList->id() === $regularList->id()) {
            return new PriceQuote(regular: $regular, final: $regular);
        }

        if ($winningList->mode() === PriceListMode::PERCENTAGE_OFF_REGULAR) {
            return new PriceQuote(regular: $regular, final: $this->applyPercentageOff($regular, $winningList->percentageBasisPoints()));
        }

        // FIXED_ITEMS: winning by priority/scope, but no item row for this
        // exact target — §4.6 forbids falling through to the next-highest
        // priority list (no blending), so this falls back to regular.
        $final = $this->cachedItemPrice($winningList, $context) ?? $regular;

        return new PriceQuote(regular: $regular, final: $final);
    }

    /**
     * Reads from the primed cache; a genuine cache MISS (this exact
     * (list, priceableId) pair was never primed) falls back to a
     * lazy, single-target prime — exactly the one-query cost
     * FixedItemsPriceLookup::forTarget() always had. This is what lets
     * EloquentPriceResolver's single-target path call buildQuote()
     * directly with no priming step of its own, while
     * EloquentPriceRangeResolver's batched path (which DOES prime
     * explicitly before looping) never falls into this branch at all.
     */
    private function cachedItemPrice(PriceList $list, PriceContext $context): ?Price
    {
        if (! array_key_exists($context->priceableId, $this->itemCache[$list->id()] ?? [])) {
            $this->primeItemCache($list, [
                $context->priceableId => ['productId' => $context->productId, 'quantity' => $context->quantity],
            ]);
        }

        return $this->itemCache[$list->id()][$context->priceableId] ?? null;
    }

    /**
     * §4.6 step 4: regular price × (1 − percentage). Byte-for-byte the
     * same algorithm EloquentPriceResolver's own applyPercentageOff()
     * used before this engine existed — see that class's prior version
     * for the identical reasoning about Money::multiply() only accepting
     * an integer quantity.
     */
    private function applyPercentageOff(Price $regular, int $percentageBasisPoints): Price
    {
        $rawMoney = $regular->isTaxInclusive() ? $regular->gross() : $regular->net();

        $discountedMinor = self::roundedDivide(
            $rawMoney->minorValue() * (10000 - $percentageBasisPoints),
            10000
        );

        $discountedMoney = Money::fromMinorUnits($discountedMinor, $rawMoney->currency());

        return $regular->isTaxInclusive()
            ? Price::inclusiveOfTax($discountedMoney, $regular->taxRateBasisPoints())
            : Price::exclusiveOfTax($discountedMoney, $regular->taxRateBasisPoints());
    }

    /**
     * Half-up integer division, byte-for-byte the same algorithm as
     * Price::roundedDivide() — that method is private to Price and not
     * reused directly on purpose: it stays Price's own internal net/gross
     * rounding helper, and this package does not widen Price's public API
     * just to share it with this one extra consumer.
     */
    private static function roundedDivide(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) === ($denominator < 0) ? 1 : -1;
        $numerator = abs($numerator);
        $denominator = abs($denominator);

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator - ($quotient * $denominator);

        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return $sign * $quotient;
    }
}
