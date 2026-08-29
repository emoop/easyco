<?php

namespace EasyCo\Media;

use InvalidArgumentException;
use LogicException;

/**
 * One variation's attachment of one MediaAsset — the domain class
 * behind `catalog_variation_media` (media-domain-design.md §2.1/§8).
 * `variationId` is a plain string id (Catalog's `Variation::id()`),
 * never a `Variation` instance — Media never depends on Catalog
 * directly, per §2.1.
 *
 * Deliberately its own class, not sharing an interface/trait with
 * ProductMedia — same shape, but each pivot stays the smallest model
 * that satisfies its own need, mirroring the "smallest model that
 * satisfies the actual need" philosophy already established elsewhere
 * in this project (e.g. `catalog-domain-design.md` §3.3).
 *
 * NO is_primary FIELD — Variant A, a confirmed decision: `sortOrder`
 * itself is the mechanism. The item at `sortOrder = 0` (the lowest
 * value) is implicitly the primary photo; making a different photo
 * primary is done by reordering, not by flipping a separate flag that
 * could drift out of sync with the ordering.
 *
 * MAX-MEDIA-PER-VARIATION NOT ENFORCED HERE: the "10 photos by
 * default, configurable" limit is a repository/guard-level concern (a
 * collection-wide count check, not something a single VariationMedia
 * instance has visibility into) — the same layering
 * RestrictedPriceWriteGuard already established above PriceListItem in
 * `EasyCo\Pricing`. Not yet implemented; a later step.
 */
final class VariationMedia
{
    public function __construct(
        private ?string $id,
        private readonly string $variationId,
        private readonly string $mediaId,
        private int $sortOrder = 0,
    ) {
        if ($variationId === '') {
            throw new InvalidArgumentException('VariationMedia variationId must not be empty.');
        }

        if ($mediaId === '') {
            throw new InvalidArgumentException('VariationMedia mediaId must not be empty.');
        }

        self::assertValidSortOrder($sortOrder);
    }

    /**
     * Reconstitutes a VariationMedia exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $variationId,
        string $mediaId,
        int $sortOrder,
    ): self {
        return new self(
            id: $id,
            variationId: $variationId,
            mediaId: $mediaId,
            sortOrder: $sortOrder,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('VariationMedia already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function variationId(): string
    {
        return $this->variationId;
    }

    public function mediaId(): string
    {
        return $this->mediaId;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function updateSortOrder(int $sortOrder): void
    {
        self::assertValidSortOrder($sortOrder);
        $this->sortOrder = $sortOrder;
    }

    private static function assertValidSortOrder(int $sortOrder): void
    {
        if ($sortOrder < 0) {
            throw new InvalidArgumentException(
                "VariationMedia sortOrder cannot be negative, got {$sortOrder}."
            );
        }
    }
}
