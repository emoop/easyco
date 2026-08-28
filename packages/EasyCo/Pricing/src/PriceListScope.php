<?php

namespace EasyCo\Pricing;

use EasyCo\Pricing\Enums\PriceListScopeType;
use InvalidArgumentException;
use LogicException;

/**
 * One condition a PriceList must match — see
 * pricing-persistence-domain-design.md §3/§4.1/§4.2. A single
 * PriceListScope is polymorphic (scopeType + scopeReferenceId, not a
 * dedicated column per dimension — §4.1); a PriceList with several
 * PriceListScope rows requires ALL of them to match (AND logic, §4.1),
 * not any one of them.
 *
 * IMMUTABLE, mirroring SaleLine's posture (operational-sales-domain-design.md
 * §3.2) — beyond assignId()/assignPriceListId() (structural/ownership
 * references, not business facts, see below), there is no mutation
 * method of any kind. Per §4.2, a scope condition is attached or
 * detached (a new PriceListScope created, or an existing one removed) —
 * never edited in place; there is deliberately no "change scopeType" or
 * "change scopeReferenceId" method here.
 *
 * WHY assignPriceListId() IS NOT A VIOLATION OF THE ABOVE:
 * priceListId is a structural/ownership reference — which PriceList this
 * scope condition currently belongs to — not a business fact like
 * scopeType or scopeReferenceId. Same precedent SaleLine::assignTransactionId()
 * itself cites: Catalog\Variation::assignProductId(), a narrow, one-time
 * backfill for a child constructed before its parent aggregate had an
 * id (CLAUDE.md rule 7). priceListId here is exactly that second kind,
 * not the first: assignPriceListId() only ever moves it from the
 * empty-string "not yet attached to a persisted PriceList" placeholder
 * to a real id, exactly once, and touches no other field.
 *
 * EXPLICITLY NOT THIS CLASS'S JOB: detecting duplicate/overlapping
 * scope conditions across sibling PriceListScope rows on the same
 * PriceList. §4.7's scope_signature (the hash that backs
 * `UNIQUE(priority, scope_signature)`) is computed across a whole
 * collection of scopes at the PriceList/persistence level — a single
 * PriceListScope instance has no visibility into its siblings and
 * cannot compute or check that itself. Deferred to later work, the
 * same posture PriceList.php's own docblock already takes toward
 * PriceListItem/scope: "not modeled on this class."
 */
final class PriceListScope
{
    /**
     * @param string $priceListId The owning PriceList's id, or the
     *   empty-string placeholder — same sentinel convention as
     *   SaleLine's transactionId — meaning this scope has not yet been
     *   attached to a persisted PriceList. See assignPriceListId()
     *   below for how the placeholder is resolved.
     */
    public function __construct(
        private ?string $id,
        private string $priceListId,
        private readonly PriceListScopeType $scopeType,
        private readonly string $scopeReferenceId,
    ) {
        if ($scopeReferenceId === '') {
            throw new InvalidArgumentException('PriceListScope scopeReferenceId must not be empty.');
        }
    }

    /**
     * Reconstitutes a PriceListScope exactly as it exists in storage.
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
        PriceListScopeType $scopeType,
        string $scopeReferenceId,
    ): self {
        return new self(
            id: $id,
            priceListId: $priceListId,
            scopeType: $scopeType,
            scopeReferenceId: $scopeReferenceId,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('PriceListScope already has an id; assignId() is a one-time operation.');
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
     * PriceListScope created before its parent PriceList had an id —
     * see the class docblock for why this narrow, one-time backfill is
     * not a violation of this class's immutability. Mirrors
     * SaleLine::assignTransactionId() exactly.
     */
    public function assignPriceListId(string $priceListId): void
    {
        if ($this->priceListId !== '') {
            throw new LogicException('PriceListScope already has a priceListId; assignPriceListId() is a one-time operation.');
        }

        $this->priceListId = $priceListId;
    }

    public function scopeType(): PriceListScopeType
    {
        return $this->scopeType;
    }

    public function scopeReferenceId(): string
    {
        return $this->scopeReferenceId;
    }
}
