<?php

namespace EasyCo\Pricing;

use InvalidArgumentException;
use LogicException;

/**
 * What a priceable actually costs EasyCo's merchant, for margin
 * calculation — see checkout-domain-design.md §9 / pricing-domain-
 * design.md §2.4. INTERNAL to this package (see
 * Contracts\ProductCostRepository's own docblock).
 *
 * MUTABILITY — SAME POSTURE AS PriceListItem, NOT SaleLine/
 * PriceListScope: a ProductCost represents *current configuration*
 * ("what this costs right now"), not a historical fact. A merchant
 * editing an existing cost is a genuine in-place edit of the same row,
 * not a new historical record — nothing in the design requires a
 * cost-change history. priceableId is structural identity (immutable,
 * no change method, same as PriceListItem's targetId); cost is mutable
 * configuration (updateCost()).
 *
 * NO assignPriceableIdBackfill()-STYLE METHOD — unlike
 * PriceListItem.priceListId or PromotionRedemption's own missing
 * assignPromotionId(), priceableId here has no placeholder-then-
 * backfill scenario: a ProductCost is only ever created once the
 * Catalog priceable it prices already has a real id. Do not
 * "helpfully" add one by copying PriceListItem's shape uncritically.
 */
final class ProductCost
{
    public function __construct(
        private ?string $id,
        private readonly string $priceableId,
        private Money $cost,
    ) {
        if ($priceableId === '') {
            throw new InvalidArgumentException('ProductCost priceableId must not be empty.');
        }
    }

    /**
     * Reconstitutes a ProductCost exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(string $id, string $priceableId, Money $cost): self
    {
        return new self(id: $id, priceableId: $priceableId, cost: $cost);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ProductCost already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function priceableId(): string
    {
        return $this->priceableId;
    }

    public function cost(): Money
    {
        return $this->cost;
    }

    /**
     * REJECTS a currency change, not just any Money — priceableId+
     * currency together are this row's real identity (see the
     * migration's unique constraint on exactly that pair); changing
     * currency here would silently make this row represent a different
     * (priceable, currency) pair than the one it was uniquely keyed
     * for. Storing a cost in a different currency for the same
     * priceable is a DIFFERENT ProductCost row, not an edit of this one.
     */
    public function updateCost(Money $cost): void
    {
        if (! $cost->currency()->equals($this->cost->currency())) {
            throw new InvalidArgumentException(
                "ProductCost cannot change currency via updateCost() — this row is keyed to \"{$this->cost->currency()->code()}\", got \"{$cost->currency()->code()}\". A different currency is a different ProductCost row."
            );
        }

        $this->cost = $cost;
    }
}
