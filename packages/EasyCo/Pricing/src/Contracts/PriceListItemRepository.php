<?php

namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\PriceListItem;

interface PriceListItemRepository
{
    /** Insert or update — price/minQuantity are mutable, unlike a scope. */
    public function save(PriceListItem $item): void;

    public function remove(string $itemId): void;

    /** @return PriceListItem[] */
    public function findByPriceListId(string $priceListId): array;
}
