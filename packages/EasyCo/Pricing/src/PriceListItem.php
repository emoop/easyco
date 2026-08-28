<?php

namespace EasyCo\Pricing;

use EasyCo\Pricing\Enums\PriceListItemTargetType;
use InvalidArgumentException;
use LogicException;

/**
 * A fixed price for one target (a Product or a Variation, §4.3) within
 * a FIXED_ITEMS PriceList — see pricing-persistence-domain-design.md
 * §3/§4.3/§4.4.
 *
 * MUTABILITY — DELIBERATELY DIFFERENT FROM PriceListScope/SaleLine, AND
 * WHY: PriceListScope is fully immutable (an attach/detach philosophy —
 * §4.2) and SaleLine is immutable because it records a historical fact
 * that must never be rewritten (operational-sales-domain-design.md
 * §3.2). A PriceListItem is neither of those things — it represents
 * *current configuration* ("how much this costs right now"), not a
 * historical event. Nothing in the design requires a price-change
 * history, so changing an existing item's price is a genuine in-place
 * edit of the same row, not a delete-and-recreate: `price` and
 * `minQuantity` are therefore mutable via updatePrice()/
 * updateMinQuantity(). `targetType`/`targetId` stay immutable with no
 * change method, for the opposite reason: changing what a
 * PriceListItem prices is a different item (a different
 * product/variation being priced), not an edit of this one — exactly
 * the same "structural identity vs. mutable configuration" line
 * PriceListScope's own docblock draws between its immutable
 * scopeType/scopeReferenceId and its structural priceListId backfill.
 *
 * EXPLICITLY NOT THIS CLASS'S JOB: cascading a price change into other
 * PriceLists that might now be inconsistent with it (e.g. a wholesale
 * list's price no longer being below this item's new regular price).
 * §4.8's mitigation for that — a write-time check plus an on-demand,
 * catalog-wide health-check report — is a separate, higher layer: the
 * operator is warned and decides, nothing here silently cascades a
 * change on their behalf. This entity only ever updates its own price.
 *
 * WHY assignPriceListId() IS NOT A VIOLATION OF §4.3's "current
 * configuration, not a historical fact" framing above: priceListId is a
 * structural/ownership reference — which PriceList this item currently
 * belongs to — not configuration content like price or minQuantity.
 * Direct precedent: PriceListScope::assignPriceListId(), the last place
 * this exact pattern was used in this package (itself following
 * SaleLine::assignTransactionId() / Catalog\Variation::assignProductId(),
 * per CLAUDE.md rule 7). Same shape here: assignPriceListId() only ever
 * moves priceListId from the empty-string "not yet attached to a
 * persisted PriceList" placeholder to a real id, exactly once.
 */
final class PriceListItem
{
    /**
     * @param string $priceListId The owning PriceList's id, or the
     *   empty-string placeholder — see assignPriceListId() below for
     *   how the placeholder is resolved.
     */
    public function __construct(
        private ?string $id,
        private string $priceListId,
        private readonly PriceListItemTargetType $targetType,
        private readonly string $targetId,
        private Price $price,
        private int $minQuantity = 1,
    ) {
        if ($targetId === '') {
            throw new InvalidArgumentException('PriceListItem targetId must not be empty.');
        }

        self::assertValidMinQuantity($minQuantity);
    }

    private static function assertValidMinQuantity(int $minQuantity): void
    {
        if ($minQuantity < 1) {
            throw new InvalidArgumentException(
                "PriceListItem minQuantity must be at least 1, got {$minQuantity}."
            );
        }
    }

    /**
     * Reconstitutes a PriceListItem exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $priceListId,
        PriceListItemTargetType $targetType,
        string $targetId,
        Price $price,
        int $minQuantity,
    ): self {
        return new self(
            id: $id,
            priceListId: $priceListId,
            targetType: $targetType,
            targetId: $targetId,
            price: $price,
            minQuantity: $minQuantity,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('PriceListItem already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function priceListId(): string
    {
        return $this->priceListId;
    }

    /**
     * Back-fills priceListId once the owning PriceList aggregate itself
     * has been persisted and assigned one. Only meaningful for a
     * PriceListItem created before its parent PriceList had an id — see
     * the class docblock for why this narrow, one-time backfill is not
     * a violation of targetType/targetId's immutability. Mirrors
     * PriceListScope::assignPriceListId() exactly.
     */
    public function assignPriceListId(string $priceListId): void
    {
        if ($this->priceListId !== '') {
            throw new LogicException('PriceListItem already has a priceListId; assignPriceListId() is a one-time operation.');
        }

        $this->priceListId = $priceListId;
    }

    public function targetType(): PriceListItemTargetType
    {
        return $this->targetType;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }

    public function minQuantity(): int
    {
        return $this->minQuantity;
    }

    public function updateMinQuantity(int $minQuantity): void
    {
        self::assertValidMinQuantity($minQuantity);
        $this->minQuantity = $minQuantity;
    }

    public function price(): Price
    {
        return $this->price;
    }

    public function updatePrice(Price $price): void
    {
        $this->price = $price;
    }
}
