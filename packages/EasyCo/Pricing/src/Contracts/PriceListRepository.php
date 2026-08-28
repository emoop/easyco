<?php

namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\PriceList;

interface PriceListRepository
{
    public function save(PriceList $priceList): void;

    public function findById(string $id): ?PriceList;

    /**
     * True if an ACTIVE PriceList already exists at $priority.
     * $excludingId excludes one list (itself) from the check — the
     * shape a future Admin UI's priority-collision warning needs when
     * editing an already-persisted list, without the check tripping
     * over the list's own unchanged priority.
     */
    public function existsActiveAtPriority(int $priority, ?string $excludingId = null): bool;

    /**
     * @return PriceList[] Every ACTIVE PriceList valid at $at, ordered by
     *   priority DESC, id DESC — this ordering directly encodes §4.6's
     *   tie-break rule (highest priority wins; on an exact tie, the
     *   more-recently-created list wins), so the FIRST scope-matching
     *   candidate the resolver finds in this order is always correct.
     */
    public function findAllActiveAndValidAt(\DateTimeImmutable $at): array;

    /**
     * Looks up one of the two reserved system PriceLists by its exact
     * name. Safe as a lookup key specifically because rename() is
     * blocked for is_system lists (CannotModifySystemPriceListException)
     * — the name is guaranteed immutable once seeded.
     */
    public function findSystemListByName(string $name): ?PriceList;
}
