<?php

namespace EasyCo\OperationalSales;

use DateTimeImmutable;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

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
     *
     * PRIVATE — operational-sales-domain-design.md §3.13 stage 4b.
     * Every caller now goes through a named factory instead:
     * create() (fresh SALE lines, enforces every §3.13 invariant
     * including productName/sku), createNonSale() (fresh RESERVATION/
     * REFUND/SHIPPING/INSTALLMENT_PAYMENT lines, Tier A only), or
     * reconstituteFromStorage() (persistence-layer only, Tier A only,
     * trusts already-validated storage). This constructor itself only
     * ever runs Tier A (structural, type-driven) validation — "required
     * for a fresh SALE line" (Tier B) lives exclusively in create() now,
     * never here.
     */
    private function __construct(
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
        private ?string $productName = null,
        private ?string $sku = null,
        private ?Money $regularUnitPrice = null,
        private ?Money $finalUnitPrice = null,
        private ?Money $promotionDiscountShare = null,
        private ?Money $discretionaryDiscount = null,
        private ?Money $netPaidAmount = null,
        private ?array $soldAttributes = null,
        private ?Money $unitCost = null,
    ) {
        if ($clientId === '') {
            throw new InvalidArgumentException('SaleLine clientId must not be empty.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException("SaleLine quantity must be a positive integer, got {$quantity}.");
        }

        self::assertPriceableIdMatchesType($priceableId, $type);
        self::assertOriginatingSaleLineIdMatchesType($originatingSaleLineId, $type);
        self::assertOriginatingReservationLineIdMatchesType($originatingReservationLineId, $type);

        // Tier A only — structural, driven purely by $type, ALWAYS
        // enforced regardless of caller (create()/createNonSale()/
        // reconstituteFromStorage() alike). Tier B ("required for a
        // fresh SALE line") is enforced by create() itself, never here —
        // operational-sales-domain-design.md §3.12's own amendment /
        // §3.13 E-D5 / stage 4b.
        self::assertProductNameAndSkuStructurallyValid($productName, $sku, $type);
        self::assertSnapshotFieldsStructurallyValid(
            $regularUnitPrice,
            $finalUnitPrice,
            $promotionDiscountShare,
            $discretionaryDiscount,
            $netPaidAmount,
            $soldAttributes,
            $unitCost,
            $type,
        );
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
            throw new InvalidArgumentException(
                "SaleLine priceableId must be null for type {$type->value}."
            );
        }

        if (! $mustBeNull && ($priceableId === null || $priceableId === '')) {
            throw new InvalidArgumentException(
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
            throw new InvalidArgumentException(
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
            throw new InvalidArgumentException(
                "SaleLine originatingReservationLineId may only be set when type is SALE, got {$type->value}."
            );
        }
    }

    /**
     * Tier A half of the §3.12 productName/sku rule — a fact about the
     * line's TYPE, true regardless of when the row was written, so it
     * runs unconditionally (construction AND reconstitution alike):
     * must be null for SHIPPING/INSTALLMENT_PAYMENT. RESERVATION/REFUND
     * stay unconstrained (either state acceptable — §3.12's own
     * reasoning). SALE's "must be present" requirement is Tier B, see
     * assertProductNameAndSkuPresentForFreshSale() below.
     */
    private static function assertProductNameAndSkuStructurallyValid(?string $productName, ?string $sku, SaleLineType $type): void
    {
        $mustBeNull = in_array($type, [SaleLineType::SHIPPING, SaleLineType::INSTALLMENT_PAYMENT], true);

        if ($mustBeNull && ($productName !== null || $sku !== null)) {
            throw new InvalidArgumentException(
                "SaleLine productName/sku must be null for type {$type->value}."
            );
        }
    }

    /**
     * Tier B half of the §3.12 productName/sku rule — "required for a
     * fresh SALE line." Called ONLY from create() (stage 4b), never from
     * the constructor — so reconstituteFromStorage() never runs it at
     * all, and a legacy row with a genuinely NULL product_name/sku reads
     * back cleanly instead of throwing — the exact defect found during
     * Prompt Г (admin-panel-design.md §14), fixed here.
     */
    private static function assertProductNameAndSkuPresentForFreshSale(?string $productName, ?string $sku): void
    {
        if ($productName === null || $productName === '') {
            throw new InvalidArgumentException(
                'SaleLine productName must be a non-empty string for type sale.'
            );
        }

        if ($sku === null || $sku === '') {
            throw new InvalidArgumentException(
                'SaleLine sku must be a non-empty string for type sale.'
            );
        }
    }

    /**
     * Tier A for every §3.13 snapshot field — the same structural rule
     * as productName/sku's own Tier A: must be null for
     * SHIPPING/INSTALLMENT_PAYMENT (neither pseudo-line type has a real
     * priceable to snapshot a price/attribute/cost for), unconstrained
     * for RESERVATION/REFUND. UNLIKE productName/sku, there is no Tier B
     * check for these fields inside the constructor at all — "required
     * for a fresh SALE line" is enforced ONLY by SaleLine::create()
     * below, never here, so createNonSale()'s callers can freely pass
     * these fields as optional/nullable for the types that allow them.
     */
    private static function assertSnapshotFieldsStructurallyValid(
        ?Money $regularUnitPrice,
        ?Money $finalUnitPrice,
        ?Money $promotionDiscountShare,
        ?Money $discretionaryDiscount,
        ?Money $netPaidAmount,
        ?array $soldAttributes,
        ?Money $unitCost,
        SaleLineType $type,
    ): void {
        $mustBeNull = in_array($type, [SaleLineType::SHIPPING, SaleLineType::INSTALLMENT_PAYMENT], true);

        if (! $mustBeNull) {
            return;
        }

        if ($regularUnitPrice !== null || $finalUnitPrice !== null || $promotionDiscountShare !== null
            || $discretionaryDiscount !== null || $netPaidAmount !== null || $soldAttributes !== null
            || $unitCost !== null) {
            throw new InvalidArgumentException(
                "SaleLine's §3.13 snapshot fields must be null for type {$type->value}."
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
     * run here. This factory therefore delegates to the same (now
     * private) constructor as create()/createNonSale(), so Tier A
     * cross-validation still runs; it is not bypassed.
     *
     * TIER B NEVER RUNS HERE AT ALL (§3.12's amendment / §3.13 E-D5,
     * stage 4b): the productName/sku-required-for-SALE rule lives
     * exclusively in create() now, not in the constructor this method
     * also calls — so a row written before either shipped reads back
     * cleanly with genuinely NULL fields, with no flag or gate needed to
     * suppress anything.
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
        ?string $productName = null,
        ?string $sku = null,
        ?Money $regularUnitPrice = null,
        ?Money $finalUnitPrice = null,
        ?Money $promotionDiscountShare = null,
        ?Money $discretionaryDiscount = null,
        ?Money $netPaidAmount = null,
        ?array $soldAttributes = null,
        ?Money $unitCost = null,
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
            productName: $productName,
            sku: $sku,
            regularUnitPrice: $regularUnitPrice,
            finalUnitPrice: $finalUnitPrice,
            promotionDiscountShare: $promotionDiscountShare,
            discretionaryDiscount: $discretionaryDiscount,
            netPaidAmount: $netPaidAmount,
            soldAttributes: $soldAttributes,
            unitCost: $unitCost,
        );
    }

    /**
     * The strict path for a freshly-created SALE line —
     * operational-sales-domain-design.md §3.13. Requires every §3.13
     * snapshot field except unitCost (nullable even here — §3.13 Q2,
     * NULL means genuinely unknown, not zero), plus productName/sku
     * (enforced for free by the constructor call below, since this is a
     * fresh, non-reconstituting construction). Enforces §3.13's own
     * invariants: same currency across every Money field,
     * netPaidAmount computed exactly by the CALLER and validated here —
     * never silently recomputed — the same "cheap corruption detector,
     * not implicit trust" posture reconstituteFromStorage()'s own
     * docblock already uses for Variation's signature check;
     * netPaidAmount >= 0; both discount fields >= 0.
     *
     * SALE-SPECIFIC BY DESIGN — does not take a $type parameter at all;
     * building a REFUND/RESERVATION/SHIPPING/INSTALLMENT_PAYMENT line
     * goes through createNonSale() instead. §3.13 only specifies these
     * fields' meaning for a SALE line; extending create() to other types
     * is not this stage's job.
     *
     * productName/sku's Tier B check (assertProductNameAndSkuPresentForFreshSale(),
     * stage 4b) also lives here — the PHP type system alone rejects a
     * null, but not an empty string, hence the explicit call below.
     *
     * @param array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}> $soldAttributes
     */
    public static function create(
        string $transactionId,
        string $clientId,
        string $priceableId,
        SaleLineStatus $status,
        int $quantity,
        Money $amount,
        Money $profit,
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $effectiveAt,
        string $productName,
        string $sku,
        Money $regularUnitPrice,
        Money $finalUnitPrice,
        Money $promotionDiscountShare,
        Money $discretionaryDiscount,
        Money $netPaidAmount,
        array $soldAttributes,
        ?Money $unitCost = null,
        ?string $originatingReservationLineId = null,
    ): self {
        self::assertProductNameAndSkuPresentForFreshSale($productName, $sku);
        self::assertSoldAttributesShape($soldAttributes);
        self::assertSnapshotFieldsSameCurrency(
            $amount,
            $regularUnitPrice,
            $finalUnitPrice,
            $promotionDiscountShare,
            $discretionaryDiscount,
            $netPaidAmount,
            $unitCost,
        );
        self::assertAmountFormula($amount, $finalUnitPrice, $quantity);
        self::assertNetPaidAmountFormula($finalUnitPrice, $quantity, $promotionDiscountShare, $discretionaryDiscount, $netPaidAmount);
        self::assertDiscountsNotNegative($promotionDiscountShare, $discretionaryDiscount);

        return new self(
            id: null,
            transactionId: $transactionId,
            clientId: $clientId,
            priceableId: $priceableId,
            type: SaleLineType::SALE,
            status: $status,
            quantity: $quantity,
            amount: $amount,
            profit: $profit,
            recordedAt: $recordedAt,
            effectiveAt: $effectiveAt,
            originatingReservationLineId: $originatingReservationLineId,
            productName: $productName,
            sku: $sku,
            regularUnitPrice: $regularUnitPrice,
            finalUnitPrice: $finalUnitPrice,
            promotionDiscountShare: $promotionDiscountShare,
            discretionaryDiscount: $discretionaryDiscount,
            netPaidAmount: $netPaidAmount,
            soldAttributes: $soldAttributes,
            unitCost: $unitCost,
        );
    }

    /**
     * The fresh-construction path for every type EXCEPT SALE —
     * operational-sales-domain-design.md §3.13 stage 4b (D9). create()
     * above stays SALE-only (SALE is the one type §3.13 actually
     * specifies field meanings for); this is the single remaining path
     * for a fresh RESERVATION/REFUND/SHIPPING/INSTALLMENT_PAYMENT line —
     * Tier A (structural, type-driven) only, enforced by the constructor
     * itself. No Tier B check of any kind: RESERVATION/REFUND are
     * unconstrained on productName/sku/the §3.13 fields by design (§3.12,
     * §3.13's own "RESERVATION lines" section — reservation-recording
     * isn't wired end-to-end yet), and SHIPPING/INSTALLMENT_PAYMENT are
     * already forced null by Tier A.
     *
     * REFUSES SaleLineType::SALE outright — SALE has its own, stricter
     * factory (create()) with real formula invariants this method does
     * not and should not replicate; a caller wanting a SALE line must
     * use create() instead.
     *
     * RETURNS AND RESERVATIONS WILL EACH GET THEIR OWN STRICT FACTORY
     * WHEN DESIGNED (mirroring create()'s own formula-invariant
     * enforcement, once §3.13's own deferred REFUND/RESERVATION design
     * items — §3.4's rewrite, reservation-recording — are actually
     * built). Until then, this is the SOLE path for every non-SALE
     * type — do not special-case REFUND/RESERVATION validation into this
     * method piecemeal; give each its own factory instead, the same way
     * create() exists for SALE.
     *
     * @param array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>|null $soldAttributes
     */
    public static function createNonSale(
        SaleLineType $type,
        string $transactionId,
        string $clientId,
        ?string $priceableId,
        SaleLineStatus $status,
        int $quantity,
        Money $amount,
        Money $profit,
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $effectiveAt,
        ?string $originatingSaleLineId = null,
        ?string $originatingReservationLineId = null,
        ?string $productName = null,
        ?string $sku = null,
        ?Money $regularUnitPrice = null,
        ?Money $finalUnitPrice = null,
        ?Money $promotionDiscountShare = null,
        ?Money $discretionaryDiscount = null,
        ?Money $netPaidAmount = null,
        ?array $soldAttributes = null,
        ?Money $unitCost = null,
    ): self {
        if ($type === SaleLineType::SALE) {
            throw new InvalidArgumentException(
                'SaleLine::createNonSale() does not accept SaleLineType::SALE — use SaleLine::create() instead.'
            );
        }

        return new self(
            id: null,
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
            productName: $productName,
            sku: $sku,
            regularUnitPrice: $regularUnitPrice,
            finalUnitPrice: $finalUnitPrice,
            promotionDiscountShare: $promotionDiscountShare,
            discretionaryDiscount: $discretionaryDiscount,
            netPaidAmount: $netPaidAmount,
            soldAttributes: $soldAttributes,
            unitCost: $unitCost,
        );
    }

    /**
     * @param array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}> $soldAttributes
     */
    private static function assertSoldAttributesShape(array $soldAttributes): void
    {
        $requiredKeys = ['definitionId', 'definitionCode', 'definitionName', 'valueId', 'value'];

        foreach ($soldAttributes as $index => $attribute) {
            if (! is_array($attribute) || array_diff($requiredKeys, array_keys($attribute)) !== []) {
                throw new InvalidArgumentException(
                    "SaleLine::create(): soldAttributes[{$index}] must have exactly the keys: ".implode(', ', $requiredKeys).'.'
                );
            }
        }
    }

    /**
     * §3.13's own invariant: same currency across amount,
     * regularUnitPrice, finalUnitPrice, promotionDiscountShare,
     * discretionaryDiscount, netPaidAmount, and unitCost (when set) —
     * amount is the anchor every other field is checked against.
     */
    private static function assertSnapshotFieldsSameCurrency(
        Money $amount,
        Money $regularUnitPrice,
        Money $finalUnitPrice,
        Money $promotionDiscountShare,
        Money $discretionaryDiscount,
        Money $netPaidAmount,
        ?Money $unitCost,
    ): void {
        $expected = $amount->currency();

        $fields = [
            'regularUnitPrice' => $regularUnitPrice,
            'finalUnitPrice' => $finalUnitPrice,
            'promotionDiscountShare' => $promotionDiscountShare,
            'discretionaryDiscount' => $discretionaryDiscount,
            'netPaidAmount' => $netPaidAmount,
            'unitCost' => $unitCost,
        ];

        foreach ($fields as $name => $money) {
            if ($money !== null && ! $money->currency()->equals($expected)) {
                throw new InvalidArgumentException(
                    "SaleLine::create(): {$name}'s currency ({$money->currency()->code()}) must match ".
                    "amount's currency ({$expected->code()})."
                );
            }
        }
    }

    /**
     * §3.13's own invariant: amount == finalUnitPrice x quantity,
     * EXACTLY — amount is §2's own pre-existing field (unchanged meaning,
     * §3.13's own "amount's meaning" recommendation), finalUnitPrice is
     * §3.13's new one; both describe the SAME pre-discount line value,
     * so a caller that passes an amount not actually derived from its
     * own finalUnitPrice/quantity has a real bug — caught here, the same
     * "cheap corruption detector, not implicit trust" posture as
     * assertNetPaidAmountFormula() just below.
     */
    private static function assertAmountFormula(Money $amount, Money $finalUnitPrice, int $quantity): void
    {
        $expected = $finalUnitPrice->multiply($quantity);

        if (! $expected->equals($amount)) {
            throw new InvalidArgumentException(
                "SaleLine::create(): amount ({$amount->minorValue()}) does not equal ".
                "finalUnitPrice x quantity ({$expected->minorValue()})."
            );
        }
    }

    /**
     * §3.13's own invariant: netPaidAmount == finalUnitPrice x quantity
     * - promotionDiscountShare - discretionaryDiscount, EXACTLY — passed
     * explicitly by the caller and validated here, never recomputed
     * internally (a builder bug that miscalculates it is caught
     * immediately, never silently persisted). Also enforces
     * netPaidAmount >= 0 here, since a negative value would already have
     * failed the equality check against a formula that can itself be
     * negative only if the caller's own inputs were wrong.
     */
    private static function assertNetPaidAmountFormula(
        Money $finalUnitPrice,
        int $quantity,
        Money $promotionDiscountShare,
        Money $discretionaryDiscount,
        Money $netPaidAmount,
    ): void {
        $expected = $finalUnitPrice->multiply($quantity)
            ->subtract($promotionDiscountShare)
            ->subtract($discretionaryDiscount);

        if (! $expected->equals($netPaidAmount)) {
            throw new InvalidArgumentException(
                "SaleLine::create(): netPaidAmount ({$netPaidAmount->minorValue()}) does not equal ".
                "finalUnitPrice x quantity - promotionDiscountShare - discretionaryDiscount ({$expected->minorValue()})."
            );
        }

        if ($netPaidAmount->isNegative()) {
            throw new InvalidArgumentException('SaleLine::create(): netPaidAmount must not be negative.');
        }
    }

    private static function assertDiscountsNotNegative(Money $promotionDiscountShare, Money $discretionaryDiscount): void
    {
        if ($promotionDiscountShare->isNegative()) {
            throw new InvalidArgumentException('SaleLine::create(): promotionDiscountShare must not be negative.');
        }

        if ($discretionaryDiscount->isNegative()) {
            throw new InvalidArgumentException('SaleLine::create(): discretionaryDiscount must not be negative.');
        }
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

    public function productName(): ?string
    {
        return $this->productName;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    /** §3.13 — NULL on a row written before this field existed (§3.13 E-D5); never backfilled. */
    public function regularUnitPrice(): ?Money
    {
        return $this->regularUnitPrice;
    }

    /** §3.13 — NULL on a row written before this field existed (§3.13 E-D5); never backfilled. */
    public function finalUnitPrice(): ?Money
    {
        return $this->finalUnitPrice;
    }

    /** §3.13 — NULL on a row written before this field existed (§3.13 E-D5); never backfilled. */
    public function promotionDiscountShare(): ?Money
    {
        return $this->promotionDiscountShare;
    }

    /** §3.13 — NULL on a row written before this field existed (§3.13 E-D5); never backfilled. */
    public function discretionaryDiscount(): ?Money
    {
        return $this->discretionaryDiscount;
    }

    /** §3.13 — NULL on a row written before this field existed (§3.13 E-D5); never backfilled. */
    public function netPaidAmount(): ?Money
    {
        return $this->netPaidAmount;
    }

    /**
     * §3.13 — ordered list of {definitionId, definitionCode,
     * definitionName, valueId, value}, `[]` for a SIMPLE line once
     * populated, NULL only on a row written before this field existed
     * (§3.13 E-D5); never backfilled.
     *
     * @return array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>|null
     */
    public function soldAttributes(): ?array
    {
        return $this->soldAttributes;
    }

    /** §3.13 Q2 — NULL means genuinely unknown cost, not zero; stays nullable even on a fresh line. */
    public function unitCost(): ?Money
    {
        return $this->unitCost;
    }
}
