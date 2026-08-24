<?php

namespace EasyCo\Catalog;

use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Exceptions\UnsafeProductTypeTransitionException;

/**
 * The Product aggregate root.
 *
 * AUTHORITATIVE MODEL (see catalog-domain-design.md):
 * SIMPLE and VARIABLE Products are the same aggregate. Every Product owns
 * one or more Variations:
 *   - SIMPLE:   exactly one Variation, type UNIVERSAL, never selectable.
 *   - VARIABLE: one or more Variations, type STANDARD, selectable.
 *
 * This class is the ONLY place that is allowed to create or retire a
 * Variation — callers never construct Variation directly for that reason.
 * It is also the only place that validates a Variation's attribute
 * combination against the Product's declared variation axes — see
 * declareVariationAxes() / addStandardVariation() / changeVariationCombination().
 */
final class Product
{
    /** @var array<string, Variation> keyed by Variation id once persisted, or a temporary spl_object_id() before */
    private array $variations = [];

    /** @var array<string, VariationAxis> keyed by attribute_definition_id. Only meaningful for VARIABLE. */
    private array $variationAxes = [];

    public function __construct(
        private ?string $id,
        private string $name,
        private ProductType $type,
        private string $baseSku,
        private ProductStatus $status = ProductStatus::DRAFT,
        private CatalogVisibility $catalogVisibility = CatalogVisibility::HIDDEN,
    ) {
        if ($baseSku === '') {
            throw new \InvalidArgumentException('Product baseSku must not be empty.');
        }
    }

    /**
     * Creates a brand-new SIMPLE product together with its Universal
     * variation in one step — a SIMPLE product without its Universal
     * variation is not a valid state to construct at all. The Universal
     * variation's sku is set to exactly $baseSku, with no suffix: a SIMPLE
     * product has exactly one sellable thing, so a distinguishing suffix
     * would be meaningless.
     */
    public static function createSimple(string $name, string $baseSku): self
    {
        $product = new self(id: null, name: $name, type: ProductType::SIMPLE, baseSku: $baseSku);
        $universal = $product->newUniversalVariation();
        $product->variations[spl_object_id($universal)] = $universal;

        return $product;
    }

    /**
     * Builds a fresh Universal variation for this product. If this
     * Product does not have an id yet, the Variation is created with an
     * empty productId and back-filled later — see assignId() below.
     */
    private function newUniversalVariation(): Variation
    {
        return new Variation(
            id: null,
            productId: $this->id ?? '',
            type: VariationType::UNIVERSAL,
            status: VariationStatus::ACTIVE,
            attributeAssignments: [],
            attributeSignature: VariationSignature::forUniversalVariation(),
            sku: $this->baseSku,
        );
    }

    /**
     * Creates a brand-new VARIABLE product with no variations yet — the
     * merchant adds them afterwards via addStandardVariation() or a
     * VariationCombinationGenerator run.
     */
    public static function createVariable(string $name, string $baseSku): self
    {
        return new self(id: null, name: $name, type: ProductType::VARIABLE, baseSku: $baseSku);
    }

    /**
     * Reconstitutes a Product aggregate exactly as it exists in storage,
     * together with its already-persisted Variations.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that every
     * argument is already-valid data read back from storage. It does NOT
     * re-run any business validation: in particular, each Variation in
     * $variations is attached as-is, without re-checking its combination
     * against this Product's declared variation axes — that check already
     * happened once, at the moment the Variation was originally created or
     * changed via addStandardVariation()/changeVariationCombination(), and
     * this Product's VariationAxis declarations are not even loaded from
     * storage in this vertical slice (a separate, later concern — see
     * catalog-domain-design.md §6). This method is not a business
     * operation and application code must never call it directly; only a
     * repository implementation reconstructing an aggregate from
     * already-validated rows should call it.
     *
     * @param Variation[] $variations Already-reconstituted Variations
     *   (e.g. via Variation::reconstituteFromStorage()), each with its
     *   real persisted id.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $name,
        ProductType $type,
        string $baseSku,
        ProductStatus $status,
        CatalogVisibility $catalogVisibility,
        array $variations = [],
    ): self {
        $product = new self(
            id: $id,
            name: $name,
            type: $type,
            baseSku: $baseSku,
            status: $status,
            catalogVisibility: $catalogVisibility,
        );

        foreach ($variations as $variation) {
            $product->variations[$variation->id()] = $variation;
        }

        return $product;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Product already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;

        // Back-fill productId on any not-yet-persisted variation created
        // before this Product had an id (see createSimple()).
        foreach ($this->variations as $variation) {
            if ($variation->id() === null && $variation->productId() === '') {
                $variation->assignProductId($id);
            }
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function baseSku(): string
    {
        return $this->baseSku;
    }

    public function type(): ProductType
    {
        return $this->type;
    }

    public function status(): ProductStatus
    {
        return $this->status;
    }

    public function catalogVisibility(): CatalogVisibility
    {
        return $this->catalogVisibility;
    }

    public function setCatalogVisibility(CatalogVisibility $visibility): void
    {
        // Deliberately no coupling to sellability here — a HIDDEN product
        // may still have purchasable variations for POS / direct-order use.
        $this->catalogVisibility = $visibility;
    }

    /** @return Variation[] */
    public function variations(): array
    {
        return array_values($this->variations);
    }

    public function universalVariation(): ?Variation
    {
        if ($this->type !== ProductType::SIMPLE) {
            return null;
        }

        foreach ($this->variations as $variation) {
            if ($variation->type() === VariationType::UNIVERSAL) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * Declares (replacing any previous declaration) which attributes are
     * this VARIABLE product's variation axes, and which values are
     * enabled for each. This is the domain-layer equivalent of the
     * merchant's catalog_product_attributes(is_variation_axis=true) +
     * catalog_product_axis_values choices, and is what
     * addStandardVariation() / changeVariationCombination() validate
     * every combination against — see catalog-domain-design.md
     * §"Variation attribute validation".
     *
     * Deliberately a full replace, not an incremental add, to keep v1's
     * mental model simple: re-declare the whole axis set whenever it
     * changes. Existing Variations are not retroactively re-validated
     * against a new declaration — that is a merchant-workflow concern
     * (e.g. warning about now-orphaned combinations) intentionally left
     * to the application layer, not this aggregate.
     *
     * @param VariationAxis[] $axes
     */
    public function declareVariationAxes(array $axes): void
    {
        if ($this->type !== ProductType::VARIABLE) {
            throw new \LogicException('Only a VARIABLE product can declare variation axes.');
        }

        $byDefinitionId = [];
        foreach ($axes as $axis) {
            $definitionId = $axis->attributeDefinitionId();
            if (isset($byDefinitionId[$definitionId])) {
                throw new \LogicException(
                    "Attribute definition \"{$definitionId}\" was declared as a variation axis more than once."
                );
            }
            $byDefinitionId[$definitionId] = $axis;
        }

        $this->variationAxes = $byDefinitionId;
    }

    /** @return VariationAxis[] */
    public function variationAxes(): array
    {
        return array_values($this->variationAxes);
    }

    /**
     * Adds a STANDARD variation for the given axis/value combination.
     *
     * Two independent layers of protection, both required:
     *  1. Combination VALIDITY (this method, via assertValidCombination):
     *     every supplied attribute must be a declared axis of this
     *     Product, every declared axis must be supplied exactly once, and
     *     every value must be one the merchant enabled for that axis.
     *  2. Combination UNIQUENESS: an in-memory check here for a fast,
     *     friendly failure when the duplicate is already loaded in this
     *     aggregate instance, backed by the real, race-condition-safe
     *     guarantee — the DB UNIQUE(product_id, attribute_signature)
     *     index (see VariationSignature and the design doc). The
     *     in-memory check is not a substitute for the DB constraint.
     *
     * ARCHIVED-VARIATION REVIVAL: if an ARCHIVED variation of this Product
     * already occupies this exact attribute_signature (e.g. the merchant
     * archived Color:Black/Size:M and is now re-adding that same
     * combination), this method does NOT create a new row/object for it.
     * It instead transitions that exact Variation back to DRAFT via
     * Variation::reviveFromArchive(), keeping its original id/sku/barcode
     * untouched — the $sku argument passed here is deliberately ignored in
     * that case (see the branch below for why). A revived variation still
     * occupies exactly the one (product_id, attribute_signature) slot it
     * always did, so this never bypasses the DB uniqueness guarantee — it
     * simply avoids a redundant new identity for a combination the product
     * already has on file.
     *
     * @param array<int|string, int|string> $axisValueIdsByAttributeDefinitionId
     */
    public function addStandardVariation(array $axisValueIdsByAttributeDefinitionId, string $sku): Variation
    {
        if ($this->type !== ProductType::VARIABLE) {
            throw new \LogicException('Cannot add a STANDARD variation to a SIMPLE product.');
        }

        $this->assertValidCombination($axisValueIdsByAttributeDefinitionId);

        $signature = VariationSignature::forCombination($axisValueIdsByAttributeDefinitionId);

        $archived = $this->findArchivedVariationBySignature($signature);
        if ($archived !== null) {
            // Reusing the archived variation's own identity is the whole
            // point of reviving it — assigning it the freshly-supplied
            // $sku would defeat that (and could collide with the sku of
            // whatever the merchant meant to create instead). The caller
            // gets back the revived Variation and can read its actual sku
            // via sku() if it needs to know what was kept.
            $archived->reviveFromArchive();

            return $archived;
        }

        $this->assertSignatureNotAlreadyUsed($signature);

        $variation = new Variation(
            id: null,
            productId: $this->id ?? '',
            type: VariationType::STANDARD,
            status: VariationStatus::DRAFT,
            attributeAssignments: $axisValueIdsByAttributeDefinitionId,
            attributeSignature: $signature,
            sku: $sku,
        );

        // Keyed by object identity until persisted and assigned a real id.
        $this->variations[spl_object_id($variation)] = $variation;

        return $variation;
    }

    private function findArchivedVariationBySignature(VariationSignature $signature): ?Variation
    {
        foreach ($this->variations as $existing) {
            if ($existing->status() === VariationStatus::ARCHIVED
                && $existing->attributeSignature()->equals($signature)) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * The single, atomic operation for changing an existing STANDARD
     * variation's defining combination (e.g. Color:Black/Size:M ->
     * Color:Red/Size:M). Validates the new combination against the
     * declared axes, checks it doesn't collide with another existing
     * variation, computes the new signature, and swaps both assignments
     * and signature together via Variation::replaceCombination() so they
     * can never end up inconsistent with each other — see
     * catalog-domain-design.md §"Atomic variation combination changes".
     *
     * $variation must already belong to this Product (enforced below);
     * callers get it from $product->variations(), not constructed
     * independently.
     *
     * @param array<int|string, int|string> $newAxisValueIdsByAttributeDefinitionId
     */
    public function changeVariationCombination(
        Variation $variation,
        array $newAxisValueIdsByAttributeDefinitionId
    ): void {
        if (! in_array($variation, $this->variations, true)) {
            throw new \LogicException('This Variation does not belong to this Product.');
        }

        if ($variation->type() !== VariationType::STANDARD) {
            throw new \LogicException('Only a STANDARD variation has a mutable attribute combination.');
        }

        $this->assertValidCombination($newAxisValueIdsByAttributeDefinitionId);

        $newSignature = VariationSignature::forCombination($newAxisValueIdsByAttributeDefinitionId);
        $this->assertSignatureNotAlreadyUsed($newSignature, excluding: $variation);

        // Only mutate the Variation after every validation above has
        // already succeeded — a failed validation must leave the
        // variation completely untouched (no partial update).
        $variation->replaceCombination($newAxisValueIdsByAttributeDefinitionId, $newSignature);
    }

    /**
     * Validates a candidate combination against this Product's declared
     * axes:
     *   - every supplied attribute_definition_id must be a declared axis
     *   - every declared axis must be supplied (no partial combinations)
     *   - every supplied value must be one the merchant enabled for its axis
     *
     * Note that "duplicate axis assignment" (e.g. assigning two different
     * values to Color at once) is structurally impossible with this
     * method's input shape — a PHP array cannot hold two values under the
     * same key — rather than something validated at runtime. See
     * ProductStandardVariationTest for a test documenting this guarantee.
     *
     * @param array<int|string, int|string> $axisValueIdsByAttributeDefinitionId
     */
    private function assertValidCombination(array $axisValueIdsByAttributeDefinitionId): void
    {
        if ($axisValueIdsByAttributeDefinitionId === []) {
            throw InvalidVariationAxisException::missingValueForAxis('(none supplied)');
        }

        $supplied = $this->normalizeKeysToString($axisValueIdsByAttributeDefinitionId);

        foreach ($supplied as $attributeDefinitionId => $attributeValueId) {
            $axis = $this->variationAxes[$attributeDefinitionId] ?? null;
            if ($axis === null) {
                throw InvalidVariationAxisException::axisNotDeclaredForProduct($attributeDefinitionId);
            }

            if (! $axis->isAllowedValueId((string) $attributeValueId)) {
                throw InvalidVariationAxisException::valueNotAllowedForAxis($attributeDefinitionId, (string) $attributeValueId);
            }
        }

        foreach ($this->variationAxes as $definitionId => $axis) {
            if (! array_key_exists($definitionId, $supplied)) {
                throw InvalidVariationAxisException::missingValueForAxis($axis->attributeDefinitionCode());
            }
        }
    }

    /** @param array<int|string, int|string> $map */
    private function normalizeKeysToString(array $map): array
    {
        $normalized = [];
        foreach ($map as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    private function assertSignatureNotAlreadyUsed(VariationSignature $signature, ?Variation $excluding = null): void
    {
        foreach ($this->variations as $existing) {
            if ($excluding !== null && $existing === $excluding) {
                continue;
            }

            if ($existing->attributeSignature()->equals($signature)) {
                throw DuplicateVariationCombinationException::forSignature($this->id ?? '(unsaved)', $signature->value());
            }
        }
    }

    /**
     * Called by the repository after a Variation has been persisted and
     * assigned a real id, so the aggregate's in-memory collection is keyed
     * consistently.
     */
    public function reindexVariationAfterPersist(Variation $variation): void
    {
        foreach ($this->variations as $key => $candidate) {
            if ($candidate === $variation) {
                unset($this->variations[$key]);
                $this->variations[$variation->id()] = $variation;

                return;
            }
        }
    }

    /**
     * SIMPLE -> VARIABLE.
     *
     * The Universal variation is never deleted: it is archived (never
     * customer-selectable to begin with, and now also never purchasable),
     * preserving its id for any historical reference that may already
     * point at it, while the product starts accumulating real STANDARD
     * variations.
     */
    public function changeToVariable(): void
    {
        if ($this->type === ProductType::VARIABLE) {
            return;
        }

        $universal = $this->universalVariation();
        if ($universal !== null) {
            $universal->archive();
        }

        $this->type = ProductType::VARIABLE;
    }

    /**
     * VARIABLE -> SIMPLE, the safe/guarded path.
     *
     * Refuses whenever the product has ever had a STANDARD variation
     * created, because Catalog cannot see whether Orders, POS or
     * Inventory already reference that variation's id — see
     * UnsafeProductTypeTransitionException and the design doc §4.
     *
     * On success, creates a fresh Universal variation (the previous one,
     * if any, stays archived and untouched).
     */
    public function attemptConvertToSimple(): void
    {
        if ($this->type === ProductType::SIMPLE) {
            return;
        }

        $hasAnyStandardVariation = false;
        foreach ($this->variations as $variation) {
            if ($variation->type() === VariationType::STANDARD) {
                $hasAnyStandardVariation = true;

                break;
            }
        }

        if ($hasAnyStandardVariation) {
            throw UnsafeProductTypeTransitionException::becauseStandardVariationsExist($this->id ?? '(unsaved)');
        }

        $this->type = ProductType::SIMPLE;
        $fresh = $this->newUniversalVariation();
        $this->variations[spl_object_id($fresh)] = $fresh;
    }

    /**
     * VARIABLE -> SIMPLE, the explicit, unsafe escape hatch.
     *
     * Named and shaped so it can never be reached accidentally: no default
     * arguments, requires the caller to have already verified (outside
     * Catalog's boundary, since Catalog cannot see Orders/POS/Inventory)
     * that no external system references the STANDARD variations about to
     * be archived. All existing STANDARD variations are archived — never
     * deleted — and a fresh Universal variation is created.
     */
    public function forceConvertToSimple(bool $iHaveVerifiedNoExternalReferencesExist): void
    {
        if (! $iHaveVerifiedNoExternalReferencesExist) {
            throw new \InvalidArgumentException(
                'forceConvertToSimple() requires explicit confirmation that no external references exist.'
            );
        }

        foreach ($this->variations as $variation) {
            if ($variation->type() === VariationType::STANDARD && $variation->status() !== VariationStatus::ARCHIVED) {
                $variation->archive();
            }
        }

        $this->type = ProductType::SIMPLE;
        $fresh = $this->newUniversalVariation();
        $this->variations[spl_object_id($fresh)] = $fresh;
    }
}
