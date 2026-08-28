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
}
