<?php

namespace EasyCo\Promotions;

use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use InvalidArgumentException;
use LogicException;

/**
 * One eligibility condition a Promotion is checked against — see
 * promotions-domain-design.md §3. Mirrors EasyCo\Pricing\PriceListScope's
 * shape and immutability posture exactly (see that class's own docblock),
 * with one real addition: `mode` (INCLUDE/EXCLUDE — §3's resolution
 * rule), which PriceListScope has no equivalent of (§3.1).
 *
 * IMMUTABLE beyond assignId()/assignPromotionId() (structural/ownership
 * references, not business facts — same CLAUDE.md rule 7 precedent
 * PriceListScope itself cites). A scope condition is attached or
 * detached, never edited in place.
 *
 * EXPLICITLY NOT THIS CLASS'S JOB: resolving whether a Promotion applies
 * to a given line/cart. §3's resolution rule is evaluated against a
 * whole collection of PromotionScope rows by whatever consumes them —
 * a single instance has no visibility into its siblings. Same posture
 * PriceListScope's own docblock takes.
 */
final class PromotionScope
{
    /**
     * @param string $promotionId The owning Promotion's id, or the
     *   empty-string placeholder — same sentinel convention as
     *   PriceListScope's own priceListId. See assignPromotionId() below
     *   for how the placeholder is resolved.
     */
    public function __construct(
        private ?string $id,
        private string $promotionId,
        private readonly PromotionScopeType $scopeType,
        private readonly string $scopeReferenceId,
        private readonly PromotionScopeMode $mode,
    ) {
        if ($scopeReferenceId === '') {
            throw new InvalidArgumentException('PromotionScope scopeReferenceId must not be empty.');
        }
    }

    /**
     * Reconstitutes a PromotionScope exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $promotionId,
        PromotionScopeType $scopeType,
        string $scopeReferenceId,
        PromotionScopeMode $mode,
    ): self {
        return new self(
            id: $id,
            promotionId: $promotionId,
            scopeType: $scopeType,
            scopeReferenceId: $scopeReferenceId,
            mode: $mode,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('PromotionScope already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function promotionId(): string
    {
        return $this->promotionId;
    }

    /**
     * Back-fills promotionId once the owning Promotion aggregate itself
     * has been persisted and assigned one. Only meaningful for a
     * PromotionScope created before its parent Promotion had an id —
     * see the class docblock for why this narrow, one-time backfill is
     * not a violation of this class's immutability. Mirrors
     * PriceListScope::assignPriceListId() exactly.
     */
    public function assignPromotionId(string $promotionId): void
    {
        if ($this->promotionId !== '') {
            throw new LogicException('PromotionScope already has a promotionId; assignPromotionId() is a one-time operation.');
        }

        $this->promotionId = $promotionId;
    }

    public function scopeType(): PromotionScopeType
    {
        return $this->scopeType;
    }

    public function scopeReferenceId(): string
    {
        return $this->scopeReferenceId;
    }

    public function mode(): PromotionScopeMode
    {
        return $this->mode;
    }
}
