<?php

namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\PriceListItem;

interface PriceListItemRepository
{
    /** Insert or update — price/minQuantity are mutable, unlike a scope. */
    public function save(PriceListItem $item): void;

    public function remove(string $itemId): void;

    /** @return PriceListItem[] */
    public function findByPriceListId(string $priceListId): array;

    /**
     * A real, minimal addition — not filtering findByPriceListId()'s
     * full result set client-side, since a system PriceList
     * ("Regular Prices"/"Manual Sale") can accumulate one row per
     * priced Variation/Product store-wide, and the admin-UI "does THIS
     * target already have a price here" lookup this exists for
     * (products.php's own regular/sale price fields) runs on every
     * Create/Edit/View of a single product — loading every item in the
     * whole list just to find one would be real, avoidable N+1-at-scale
     * waste.
     */
    public function findByPriceListIdAndTarget(string $priceListId, PriceListItemTargetType $targetType, string $targetId): ?PriceListItem;

    /**
     * The set-based sibling of findByPriceListIdAndTarget() above — same
     * reasoning, extended to a batch: FixedItemsPriceLookup::forTargets()
     * needs "every item this list has for THESE N targets" without
     * loading the whole list (which, for a system list like "Regular
     * Prices", can hold one row per priced Variation/Product store-wide —
     * unusable for a list/grid view resolving many targets at once). One
     * whereIn query on the existing (price_list_id, target_type,
     * target_id, min_quantity) lookup index.
     *
     * @param string[] $targetIds
     * @return PriceListItem[]
     */
    public function findByPriceListIdAndTargets(string $priceListId, PriceListItemTargetType $targetType, array $targetIds): array;
}
