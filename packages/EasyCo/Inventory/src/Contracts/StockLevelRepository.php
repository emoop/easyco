<?php

namespace EasyCo\Inventory\Contracts;

use EasyCo\Inventory\StockLevel;

interface StockLevelRepository
{
    /**
     * The merchant's "set absolute quantity" flow — upserts keyed on
     * variation_id (the true 1:1 business identity here), not on
     * StockLevel's own surrogate id. See inventory-domain-design.md
     * §8 for why this deliberately differs from every other
     * repository in this codebase.
     */
    public function save(StockLevel $stockLevel): void;

    /**
     * Never null — see inventory-domain-design.md §5. When no row
     * exists yet for $variationId, returns a real, not-yet-persisted
     * StockLevel with quantity=0 (id() === null), because "no row"
     * and "zero stock" are the same fact here, not a not-found error.
     */
    public function findByVariationId(string $variationId): StockLevel;

    /**
     * Atomic, single-query conditional UPDATE — never a
     * load/mutate-in-PHP/save() round trip (inventory-domain-design.md
     * §6, the race this exists to prevent). Throws
     * InsufficientStockException if the current quantity is less than
     * $amount, or if no row exists at all (equivalent to 0 available).
     * No caller exists yet — this is the future Checkout/POS
     * write-path's job (§Deferred).
     */
    public function decrease(string $variationId, int $amount): void;

    /**
     * Atomic. Creates the row (quantity=0) first if none exists yet —
     * increment() alone is a silent no-op against zero matched rows,
     * it does not insert (inventory-domain-design.md §7). No caller
     * exists yet — see decrease() above.
     */
    public function increase(string $variationId, int $amount): void;
}
