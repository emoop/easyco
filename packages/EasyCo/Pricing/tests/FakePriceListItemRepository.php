<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\PriceListItem;

/**
 * Minimal in-memory PriceListItemRepository, test-only: exists so a
 * framework-agnostic consumer of the contract (FixedItemsPriceLookup)
 * can be tested at package level against "any PriceListItemRepository
 * implementation" without pulling in Eloquent or a real database — this
 * package's own tests are pure PHP by design (CLAUDE.md rule 1). Never
 * referenced by application code; not a production InMemory* class like
 * the one this project deliberately removed (InMemoryPriceResolver, §8
 * item 2e) — this one lives only in tests/.
 */
final class FakePriceListItemRepository implements PriceListItemRepository
{
    /** @var array<string, PriceListItem> */
    private array $items = [];

    private int $nextId = 1;

    public function save(PriceListItem $item): void
    {
        if ($item->id() === null) {
            $item->assignId((string) $this->nextId++);
        }

        $this->items[$item->id()] = $item;
    }

    public function remove(string $itemId): void
    {
        unset($this->items[$itemId]);
    }

    public function findByPriceListId(string $priceListId): array
    {
        return array_values(array_filter(
            $this->items,
            fn (PriceListItem $item) => $item->priceListId() === $priceListId
        ));
    }

    public function findByPriceListIdAndTarget(string $priceListId, PriceListItemTargetType $targetType, string $targetId): ?PriceListItem
    {
        foreach ($this->items as $item) {
            if ($item->priceListId() === $priceListId && $item->targetType() === $targetType && $item->targetId() === $targetId) {
                return $item;
            }
        }

        return null;
    }

    public function findByPriceListIdAndTargets(string $priceListId, PriceListItemTargetType $targetType, array $targetIds): array
    {
        return array_values(array_filter(
            $this->items,
            fn (PriceListItem $item) => $item->priceListId() === $priceListId
                && $item->targetType() === $targetType
                && in_array($item->targetId(), $targetIds, true)
        ));
    }
}
