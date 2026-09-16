<?php

namespace EasyCo\Media;

use InvalidArgumentException;
use LogicException;

/**
 * One product's attachment of one MediaAsset — the domain class behind
 * `catalog_product_media` (media-domain-design.md §2.1/§8). `productId`
 * is a plain string id (Catalog's `Product::id()`), never a `Product`
 * instance — Media never depends on Catalog directly, per §2.1.
 *
 * NO is_primary FIELD — Variant A, a confirmed decision: `sortOrder`
 * itself is the mechanism. The item at `sortOrder = 0` (the lowest
 * value) is implicitly the primary photo; making a different photo
 * primary is done by reordering, not by flipping a separate flag that
 * could drift out of sync with the ordering.
 *
 * MAX-MEDIA-PER-PRODUCT NOT ENFORCED HERE: the "10 photos per product
 * by default, configurable" limit is a repository/guard-level concern
 * (a collection-wide count check, not something a single ProductMedia
 * instance has visibility into) — the same layering
 * RestrictedPriceWriteGuard already established above PriceListItem in
 * `EasyCo\Pricing`. Not yet implemented; a later step.
 *
 * AUTOPLAY — whether THIS product's attachment of a video should
 * autoplay on the (not-yet-built) storefront. Lives here, on the
 * per-attachment pivot, not on MediaAsset itself: the same video could
 * in principle be attached to more than one product, each wanting a
 * different autoplay preference. Meaningless for a photo attachment —
 * nothing here rejects setting it on one anyway (no real invariant to
 * protect; a photo's autoplay flag is simply never read by anything),
 * mirroring this class's own "not this class's job to add validation
 * the constructor doesn't already have" posture elsewhere.
 */
final class ProductMedia
{
    public function __construct(
        private ?string $id,
        private readonly string $productId,
        private readonly string $mediaId,
        private int $sortOrder = 0,
        private bool $autoplay = false,
    ) {
        if ($productId === '') {
            throw new InvalidArgumentException('ProductMedia productId must not be empty.');
        }

        if ($mediaId === '') {
            throw new InvalidArgumentException('ProductMedia mediaId must not be empty.');
        }

        self::assertValidSortOrder($sortOrder);
    }

    /**
     * Reconstitutes a ProductMedia exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $productId,
        string $mediaId,
        int $sortOrder,
        bool $autoplay = false,
    ): self {
        return new self(
            id: $id,
            productId: $productId,
            mediaId: $mediaId,
            sortOrder: $sortOrder,
            autoplay: $autoplay,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ProductMedia already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function productId(): string
    {
        return $this->productId;
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

    public function autoplay(): bool
    {
        return $this->autoplay;
    }

    public function updateAutoplay(bool $autoplay): void
    {
        $this->autoplay = $autoplay;
    }

    private static function assertValidSortOrder(int $sortOrder): void
    {
        if ($sortOrder < 0) {
            throw new InvalidArgumentException(
                "ProductMedia sortOrder cannot be negative, got {$sortOrder}."
            );
        }
    }
}
