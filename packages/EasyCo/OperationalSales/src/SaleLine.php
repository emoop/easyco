<?php

namespace EasyCo\OperationalSales;

use DateTimeImmutable;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\Pricing\Money;

/**
 * A single recorded line of what actually happened: a sale, a
 * reservation, a refund, a shipping charge, or an installment payment.
 *
 * IMMUTABLE — THIS IS THE SINGLE MOST IMPORTANT RULE IN THIS CLASS (see
 * operational-sales-domain-design.md §3.2). Once constructed, a SaleLine
 * never changes — there is no setter, and no mutation method of any kind
 * beyond assignId() (which only assigns the identity a repository hands
 * back after insert; it never changes what the line records) and
 * assignTransactionId() (see below). The source system rewrote a line's
 * status in place (e.g. a `refunded` marker stamped onto the original
 * row once it was superseded) to keep its picture of "current state"
 * consistent — this project has a hard rule against that pattern,
 * already established for Catalog's Variation identity. A correction
 * here is never a mutation: it is always a NEW SaleLine, referencing the
 * line it corrects or settles via originatingSaleLineId /
 * originatingReservationLineId. The full event history is always
 * reconstructable this way; nothing is ever silently rewritten.
 *
 * WHY assignTransactionId() IS NOT A VIOLATION OF THE ABOVE:
 * transactionId is a structural/ownership reference — which Transaction
 * this line currently belongs to — not a business fact like amount,
 * status, or type. The precedent is Catalog\Variation, which draws
 * exactly this distinction: attributeAssignments is a business fact and
 * has no backfill or mutation path at all, while productId is a
 * structural reference and gets a narrow, one-time
 * assignProductId() specifically to handle a Variation created before
 * its parent Product had an id. transactionId here is the second kind,
 * not the first: assignTransactionId() only ever moves it from the
 * empty-string "not yet attached to a persisted Transaction" placeholder
 * to a real id, exactly once, and touches no other field. It does not
 * open the door to general mutation.
 */
final class SaleLine
{
    /**
     * @param string $transactionId The owning Transaction's id, or the
     *   empty-string placeholder — same sentinel convention as
     *   Catalog\Variation's not-yet-persisted productId — meaning this
     *   line has not yet been attached to a persisted Transaction. See
     *   assignTransactionId() below for how the placeholder is resolved.
     */
    public function __construct(
        private ?string $id,
        private string $transactionId,
        private string $clientId,
        private ?string $priceableId,
        private SaleLineType $type,
        private SaleLineStatus $status,
        private int $quantity,
        private Money $amount,
        private Money $profit,
        private DateTimeImmutable $recordedAt,
        private DateTimeImmutable $effectiveAt,
        private ?string $originatingSaleLineId = null,
        private ?string $originatingReservationLineId = null,
    ) {
        if ($clientId === '') {
            throw new \InvalidArgumentException('SaleLine clientId must not be empty.');
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException("SaleLine quantity must be a positive integer, got {$quantity}.");
        }

        self::assertPriceableIdMatchesType($priceableId, $type);
        self::assertOriginatingSaleLineIdMatchesType($originatingSaleLineId, $type);
        self::assertOriginatingReservationLineIdMatchesType($originatingReservationLineId, $type);
    }

    /**
     * Per §2: priceableId is null only for the two pseudo-line types that
     * don't reference a real Catalog priceable (SHIPPING, a courier cost;
     * INSTALLMENT_PAYMENT, a payment against a plan, not a product) — and
     * must be present for every other type.
     */
    private static function assertPriceableIdMatchesType(?string $priceableId, SaleLineType $type): void
    {
        $mustBeNull = in_array($type, [SaleLineType::SHIPPING, SaleLineType::INSTALLMENT_PAYMENT], true);

        if ($mustBeNull && $priceableId !== null) {
            throw new \InvalidArgumentException(
                "SaleLine priceableId must be null for type {$type->value}."
            );
        }

        if (! $mustBeNull && ($priceableId === null || $priceableId === '')) {
            throw new \InvalidArgumentException(
                "SaleLine priceableId must be a non-empty string for type {$type->value}."
            );
        }
    }

    /**
     * Per §4: originatingSaleLineId is what a REFUND uses to point back at
     * the SaleLine it refunds — no other type ever references a prior
     * sale line this way.
     */
    private static function assertOriginatingSaleLineIdMatchesType(?string $originatingSaleLineId, SaleLineType $type): void
    {
        if ($originatingSaleLineId !== null && $type !== SaleLineType::REFUND) {
            throw new \InvalidArgumentException(
                "SaleLine originatingSaleLineId may only be set when type is REFUND, got {$type->value}."
            );
        }
    }

    /**
     * Per §4: originatingReservationLineId is what a settled-reservation
     * SALE uses to point back at the RESERVATION line it settles
     * (`sold_end` / `paid_res` in the source system's taxonomy) — no
     * other type ever references a reservation this way.
     */
    private static function assertOriginatingReservationLineIdMatchesType(?string $originatingReservationLineId, SaleLineType $type): void
    {
        if ($originatingReservationLineId !== null && $type !== SaleLineType::SALE) {
            throw new \InvalidArgumentException(
                "SaleLine originatingReservationLineId may only be set when type is SALE, got {$type->value}."
            );
        }
    }

    /**
     * Reconstitutes a SaleLine exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts that every argument already passed
     * business validation once, at write time. This method is not a
     * business operation and application code must never call it
     * directly; only a repository implementation reconstructing an
     * aggregate from already-validated rows should call it.
     *
     * Unlike Variation::reconstituteFromStorage() skipping its
     * axis-declaration validation (which depends on a *sibling*
     * aggregate's data — the owning Product's declared VariationAxis
     * set — not loaded here), the type/nullable-field cross-validation
     * enforced by this class's constructor depends on nothing but the
     * fields being reconstructed themselves: it is a pure, in-memory,
     * O(1) structural check with no database lookup or sibling-aggregate
     * dependency. That makes it the same class of "cheap corruption
     * detector" as Variation's signature-vs-assignments recomputation,
     * which stays in place regardless of how a Variation is built — not
     * the same class of check as axis validation, which genuinely cannot
     * run here. This factory therefore delegates to the same constructor
     * as normal construction, so the cross-validation still runs; it is
     * not bypassed.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $transactionId,
        string $clientId,
        ?string $priceableId,
        SaleLineType $type,
        SaleLineStatus $status,
        int $quantity,
        Money $amount,
        Money $profit,
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $effectiveAt,
        ?string $originatingSaleLineId = null,
        ?string $originatingReservationLineId = null,
    ): self {
        return new self(
            id: $id,
            transactionId: $transactionId,
            clientId: $clientId,
            priceableId: $priceableId,
            type: $type,
            status: $status,
            quantity: $quantity,
            amount: $amount,
            profit: $profit,
            recordedAt: $recordedAt,
            effectiveAt: $effectiveAt,
            originatingSaleLineId: $originatingSaleLineId,
            originatingReservationLineId: $originatingReservationLineId,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('SaleLine already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    /**
     * Back-fills transactionId once the owning Transaction aggregate
     * itself has been persisted and assigned one. Only meaningful for a
     * SaleLine created before its parent Transaction had an id — see the
     * class docblock for why this narrow, one-time backfill is not a
     * violation of §3.2 immutability. Mirrors
     * Catalog\Variation::assignProductId() exactly.
     */
    public function assignTransactionId(string $transactionId): void
    {
        if ($this->transactionId !== '') {
            throw new \LogicException('SaleLine already has a transactionId; assignTransactionId() is a one-time operation.');
        }

        $this->transactionId = $transactionId;
    }

    public function transactionId(): string
    {
        return $this->transactionId;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function priceableId(): ?string
    {
        return $this->priceableId;
    }

    public function type(): SaleLineType
    {
        return $this->type;
    }

    public function status(): SaleLineStatus
    {
        return $this->status;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function profit(): Money
    {
        return $this->profit;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function effectiveAt(): DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    public function originatingSaleLineId(): ?string
    {
        return $this->originatingSaleLineId;
    }

    public function originatingReservationLineId(): ?string
    {
        return $this->originatingReservationLineId;
    }
}
