<?php

namespace EasyCo\Pricing;

use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;

/**
 * §4.3's item-level fallback (VARIATION first, then PRODUCT) followed by
 * §4.4's quantity-tier threshold lookup within whichever target was
 * found. Extracted out of EloquentPriceResolver so the write-time
 * restricted-list price guard and the price-list health-check report
 * (§4.8, §8 item 4) can reuse this exact lookup without either of them
 * needing to resolve a full PriceContext just to call it — see
 * pricing-persistence-domain-design.md §4.8.
 *
 * forTargets() IS THE ONE REAL IMPLEMENTATION of §4.3/§4.4 — forTarget()
 * is a thin, exact-signature-preserved wrapper that delegates to it with
 * a one-entry map. This replaced an earlier version of forTarget() that
 * called findByPriceListId() and filtered the WHOLE list in PHP — a
 * system list ("Regular Prices"/"Manual Sale") accumulates one row per
 * priced Variation/Product store-wide, so that whole-list load made any
 * per-target resolve() loop unusable for a list view. forTargets() uses
 * PriceListItemRepository::findByPriceListIdAndTargets() instead — at
 * most 2 queries per list per BATCH (one VARIATION-target whereIn, one
 * PRODUCT-target whereIn), regardless of how many targets are in it —
 * real performance fix, byte-for-byte identical selection behaviour.
 */
final class FixedItemsPriceLookup
{
    public function __construct(
        private readonly PriceListItemRepository $priceListItemRepository,
    ) {
    }

    /**
     * Returns null when the list has no item at all for this target —
     * the caller decides what that means (regular-price fallback for a
     * non-system list, a hard error for "Regular Prices" itself).
     */
    public function forTarget(PriceList $list, string $priceableId, ?string $productId, int $quantity): ?Price
    {
        $results = $this->forTargets($list, [
            $priceableId => ['productId' => $productId, 'quantity' => $quantity],
        ]);

        return $results[$priceableId] ?? null;
    }

    /**
     * Batched form of forTarget() — one resolved Price (or null) per
     * target, keyed by priceableId exactly like the input.
     * $targets[priceableId]['productId'] is the §4.3 PRODUCT fallback
     * target (per product, since a batch may span several products);
     * $targets[priceableId]['quantity'] is the §4.4 tier quantity (a
     * batch may mix quantities — the equivalence matrix includes
     * quantity tiers alongside everything else).
     *
     * @param array<string, array{productId: ?string, quantity: int}> $targets keyed by priceableId
     * @return array<string, ?Price> keyed by priceableId
     */
    public function forTargets(PriceList $list, array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $priceableIds = array_keys($targets);
        $variationItemsByTarget = $this->groupByTargetId(
            $this->priceListItemRepository->findByPriceListIdAndTargets($list->id(), PriceListItemTargetType::VARIATION, $priceableIds)
        );

        $productIds = array_values(array_unique(array_filter(
            array_map(static fn (array $target): ?string => $target['productId'], $targets)
        )));
        $productItemsByTarget = $productIds !== []
            ? $this->groupByTargetId(
                $this->priceListItemRepository->findByPriceListIdAndTargets($list->id(), PriceListItemTargetType::PRODUCT, $productIds)
            )
            : [];

        $results = [];
        foreach ($targets as $priceableId => $target) {
            $candidates = $variationItemsByTarget[$priceableId] ?? [];

            if ($candidates === [] && $target['productId'] !== null) {
                $candidates = $productItemsByTarget[$target['productId']] ?? [];
            }

            $results[$priceableId] = $this->selectByQuantityTier($candidates, $target['quantity']);
        }

        return $results;
    }

    /** @param PriceListItem[] $items @return array<string, PriceListItem[]> keyed by targetId */
    private function groupByTargetId(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item->targetId()][] = $item;
        }

        return $grouped;
    }

    /** @param PriceListItem[] $candidates */
    private function selectByQuantityTier(array $candidates, int $quantity): ?Price
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (PriceListItem $a, PriceListItem $b) => $b->minQuantity() <=> $a->minQuantity());

        foreach ($candidates as $candidate) {
            if ($candidate->minQuantity() <= $quantity) {
                return $candidate->price();
            }
        }

        return null;
    }
}
