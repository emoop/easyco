<?php

namespace EasyCo\Catalog;

use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;

/**
 * The concrete sellable configuration owned by exactly one Product.
 *
 * A Variation is NOT a Product (see catalog-domain-design.md). It is only
 * ever created/edited through its parent Product's aggregate boundary —
 * this class has no public constructor for exactly that reason; use
 * Product::createUniversalVariation() / Product::addStandardVariation().
 *
 * Pricing and cost are deliberately NOT fields here — see PRICING
 * OWNERSHIP note below. This class only carries what Catalog itself owns:
 * identity, SKU/barcode, attributes, media reference, visibility/
 * purchasability flags, and shipping-relevant physical properties.
 *
 * PRICING OWNERSHIP:
 * Variation exposes priceableId() (== its own id) as the identifier Pricing
 * resolves prices/cost against. Catalog never stores regular_price,
 * sale_price or cost — see pricing-domain-design.md §4, which already
 * defines PriceResolver/CostPriceProvider taking a priceableId string.
 * Duplicating price fields here would let Catalog and Pricing drift out of
 * sync, exactly the failure mode Money/Price were built to avoid at the
 * value-object level.
 *
 * SOURCE OF TRUTH FOR ATTRIBUTES:
 * $attributeAssignments (backed by catalog_variation_attribute_values) is
 * the AUTHORITATIVE representation of which value this variation has for
 * each of its Product's declared axes. $attributeSignature is only a
 * normalized, derived PROJECTION of $attributeAssignments used for the
 * database uniqueness index and fast lookups — it is never the source
 * application logic reconstructs variation state from. The constructor and
 * replaceCombination() both assert the two agree (for STANDARD variations)
 * so they can never silently drift apart; see
 * catalog-domain-design.md §"Authoritative source of variation attributes".
 */
final class Variation
{
    /**
     * @param non-empty-string|null $id Null until persisted.
     * @param array<int|string, int|string> $attributeAssignments
     *   Map of attribute_definition_id => attribute_value_id — the
     *   authoritative combination. Empty for UNIVERSAL (it has no axes by
     *   definition); must be non-empty for STANDARD.
     */
    public function __construct(
        private ?string $id,
        private string $productId,
        private readonly VariationType $type,
        private VariationStatus $status,
        private array $attributeAssignments,
        private VariationSignature $attributeSignature,
        private string $sku,
        private ?string $barcode = null,
        private bool $isVisible = true,
        private bool $isPurchasable = true,
        private ?string $shortDescription = null,
        private ?string $shippingClass = null,
        private ?int $weightGrams = null,
        private ?int $lengthMm = null,
        private ?int $widthMm = null,
        private ?int $heightMm = null,
    ) {
        if ($sku === '') {
            throw new \InvalidArgumentException('Variation sku must not be empty.');
        }

        if ($type === VariationType::UNIVERSAL) {
            // The Universal variation is never customer-selectable —
            // enforced here, not just by convention, so no caller can
            // accidentally construct a "visible Universal" variation.
            $this->isVisible = false;

            if ($attributeAssignments !== []) {
                throw new \LogicException('A UNIVERSAL variation cannot have attribute-axis assignments.');
            }
        } else {
            $this->assertSignatureMatchesAssignments($attributeAssignments, $attributeSignature);
        }
    }

    /**
     * Reconstitutes a Variation exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts that $attributeAssignments and every
     * other argument already passed business validation at the time they
     * were originally written (Product::assertValidCombination(), i.e. the
     * declared-axis / allowed-value checks). This method does not re-run
     * that validation, and could not even if it wanted to: it has no
     * access to the owning Product's VariationAxis declarations, which are
     * not loaded from storage in this vertical slice (a separate, later
     * concern — see catalog-domain-design.md §6). It DOES still recompute
     * the signature from $attributeAssignments and run
     * assertSignatureMatchesAssignments() (via the constructor below) —
     * that is a cheap, valuable corruption detector, not business
     * validation, and stays in place regardless of how a Variation is
     * built. This method is not a business operation and application code
     * must never call it directly; only a repository implementation
     * reconstructing an aggregate from already-validated rows should call
     * it.
     *
     * @param array<int|string, int|string> $attributeAssignments
     */
    public static function reconstituteFromStorage(
        string $id,
        string $productId,
        VariationType $type,
        VariationStatus $status,
        array $attributeAssignments,
        string $sku,
        ?string $barcode = null,
        bool $isVisible = true,
        bool $isPurchasable = true,
        ?string $shortDescription = null,
        ?string $shippingClass = null,
        ?int $weightGrams = null,
        ?int $lengthMm = null,
        ?int $widthMm = null,
        ?int $heightMm = null,
    ): self {
        $signature = $type === VariationType::UNIVERSAL
            ? VariationSignature::forUniversalVariation()
            : VariationSignature::forCombination($attributeAssignments);

        return new self(
            id: $id,
            productId: $productId,
            type: $type,
            status: $status,
            attributeAssignments: $attributeAssignments,
            attributeSignature: $signature,
            sku: $sku,
            barcode: $barcode,
            isVisible: $isVisible,
            isPurchasable: $isPurchasable,
            shortDescription: $shortDescription,
            shippingClass: $shippingClass,
            weightGrams: $weightGrams,
            lengthMm: $lengthMm,
            widthMm: $widthMm,
            heightMm: $heightMm,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * Assigns the identity once persisted. Repositories call this after
     * insert; the domain layer never generates its own IDs (matches how
     * the rest of EasyCo defers identity generation to persistence, e.g.
     * auto-increment / ULID at the infrastructure layer).
     */
    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Variation already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    /**
     * The AUTHORITATIVE attribute-axis assignments for this variation —
     * see the class docblock §"Source of truth for attributes". Always
     * empty for UNIVERSAL.
     *
     * @return array<int|string, int|string>
     */
    public function attributeAssignments(): array
    {
        return $this->attributeAssignments;
    }

    /**
     * The single, atomic way a STANDARD variation's defining combination
     * may change. Only Product may call this (see Product::
     * changeVariationCombination()) — it is the one place that has
     * already validated the new combination against the Product's
     * declared axes and checked for a uniqueness conflict against the
     * Product's other variations before calling here. This method's own
     * job is narrower but just as important: guarantee assignments and
     * signature can never end up inconsistent with each other, even if a
     * future caller forgets a validation step upstream.
     */
    public function replaceCombination(array $newAttributeAssignments, VariationSignature $newSignature): void
    {
        if ($this->type !== VariationType::STANDARD) {
            throw new \LogicException('Only a STANDARD variation has a mutable attribute combination.');
        }

        $this->assertSignatureMatchesAssignments($newAttributeAssignments, $newSignature);

        $this->attributeAssignments = $newAttributeAssignments;
        $this->attributeSignature = $newSignature;
    }

    /**
     * The hard guarantee behind "signature is a projection, never the
     * source of truth": it is structurally impossible to construct or
     * mutate a STANDARD Variation into a state where attribute_signature
     * does not correspond to attribute_assignments, because this
     * recomputes the signature from the assignments itself and compares —
     * it does not trust a signature handed in from outside to already be
     * correct.
     */
    private function assertSignatureMatchesAssignments(array $assignments, VariationSignature $signature): void
    {
        $expected = VariationSignature::forCombination($assignments);

        if (! $expected->equals($signature)) {
            throw new \LogicException(
                'attribute_signature does not correspond to attribute_assignments — refusing to construct an '.
                'inconsistent Variation. This should be unreachable in practice: it means a caller computed the '.
                'signature from different assignments than the ones it passed in.'
            );
        }
    }

    /**
     * Back-fills the parent id once the Product aggregate itself has been
     * persisted and assigned one. Only meaningful for a Variation created
     * before its parent Product had an id (see Product::createSimple()).
     */
    public function assignProductId(string $productId): void
    {
        if ($this->productId !== '') {
            throw new \LogicException('Variation already has a productId; assignProductId() is a one-time operation.');
        }

        $this->productId = $productId;
    }

    public function type(): VariationType
    {
        return $this->type;
    }

    public function status(): VariationStatus
    {
        return $this->status;
    }

    public function attributeSignature(): VariationSignature
    {
        return $this->attributeSignature;
    }

    /**
     * The identifier other domains (Pricing, Inventory, Cart, POS, Orders)
     * key off of. Intentionally the same as id() — a distinct accessor
     * name documents *why* callers reach for it.
     */
    public function priceableId(): ?string
    {
        return $this->id;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): void
    {
        if ($sku === '') {
            throw new \InvalidArgumentException('Variation sku must not be empty.');
        }

        $this->sku = $sku;
    }

    public function barcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): void
    {
        $this->barcode = $barcode;
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    /**
     * Visibility here is Catalog's own signal only (see CatalogVisibility
     * docblock) — it does not by itself decide whether the variation is
     * sellable; see isEffectivelyPurchasable().
     */
    public function setVisible(bool $visible): void
    {
        if ($this->type === VariationType::UNIVERSAL && $visible) {
            throw new \LogicException('The Universal variation can never be made customer-visible.');
        }

        $this->isVisible = $visible;
    }

    public function isPurchasable(): bool
    {
        return $this->isPurchasable;
    }

    public function setPurchasable(bool $purchasable): void
    {
        $this->isPurchasable = $purchasable;
    }

    /**
     * The single answer to "can this actually be sold right now", folding
     * in the DRAFT/ARCHIVED lifecycle states so callers (Cart, POS) never
     * have to separately check status() themselves.
     */
    public function isEffectivelyPurchasable(): bool
    {
        return $this->status === VariationStatus::ACTIVE && $this->isPurchasable;
    }

    public function activate(): void
    {
        if ($this->status === VariationStatus::ARCHIVED) {
            throw new \LogicException('An archived variation cannot be reactivated directly; create a new one.');
        }

        $this->status = VariationStatus::ACTIVE;
    }

    /**
     * Retires the variation without deleting it — historical references
     * from Orders/POS/Inventory must remain valid forever.
     */
    public function archive(): void
    {
        $this->status = VariationStatus::ARCHIVED;
        $this->isVisible = false;
        $this->isPurchasable = false;
    }

    /**
     * Revives a previously ARCHIVED variation back to DRAFT, keeping its
     * existing identity — id, sku, barcode, everything — completely
     * untouched. This exists so Product::addStandardVariation() can reuse
     * an archived variation's identity when the merchant re-creates the
     * exact same attribute combination, instead of creating a brand-new
     * row/object for a combination the product already has on file.
     *
     * Deliberately NOT the same operation as activate(), and must not be
     * merged into it: activate() only ever allows DRAFT -> ACTIVE and must
     * keep refusing a directly-archived variation — a merchant explicitly
     * retiring a variation is not undone by casually reactivating it.
     * reviveFromArchive() is the one and only sanctioned ARCHIVED -> DRAFT
     * transition, reserved for this specific "the system is reusing an
     * existing identity for a regenerated combination" case — only
     * Product::addStandardVariation() calls it, never application code
     * directly.
     */
    public function reviveFromArchive(): void
    {
        if ($this->status !== VariationStatus::ARCHIVED) {
            throw new \LogicException('reviveFromArchive() only applies to an ARCHIVED variation.');
        }

        $this->status = VariationStatus::DRAFT;
    }

    public function shortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): void
    {
        $this->shortDescription = $shortDescription;
    }

    public function shippingClass(): ?string
    {
        return $this->shippingClass;
    }

    public function setShippingClass(?string $shippingClass): void
    {
        $this->shippingClass = $shippingClass;
    }

    public function weightGrams(): ?int
    {
        return $this->weightGrams;
    }

    /**
     * Physical dimensions in millimetres (integers), not e.g. centimetres
     * as a float — the same "no float drift" reasoning Money applies to
     * currency applies here to any measurement two systems (Catalog,
     * Shipping) need to agree on exactly.
     */
    public function setDimensions(?int $weightGrams, ?int $lengthMm, ?int $widthMm, ?int $heightMm): void
    {
        $this->weightGrams = $weightGrams;
        $this->lengthMm = $lengthMm;
        $this->widthMm = $widthMm;
        $this->heightMm = $heightMm;
    }

    public function lengthMm(): ?int
    {
        return $this->lengthMm;
    }

    public function widthMm(): ?int
    {
        return $this->widthMm;
    }

    public function heightMm(): ?int
    {
        return $this->heightMm;
    }
}
