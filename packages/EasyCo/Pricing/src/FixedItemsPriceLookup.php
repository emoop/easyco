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
        $items = $this->priceListItemRepository->findByPriceListId($list->id());

        $candidates = array_values(array_filter(
            $items,
            fn (PriceListItem $item) => $item->targetType() === PriceListItemTargetType::VARIATION
                && $item->targetId() === $priceableId
        ));

        if ($candidates === [] && $productId !== null) {
            $candidates = array_values(array_filter(
                $items,
                fn (PriceListItem $item) => $item->targetType() === PriceListItemTargetType::PRODUCT
                    && $item->targetId() === $productId
            ));
        }

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
