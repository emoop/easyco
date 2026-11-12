<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;
use LogicException;

/**
 * One product's assignment to one Collection — the domain class behind
 * `catalog_product_collections`. `collectionId` is a plain string id (Collection::id()), not
 * a Collection instance — same pivot-association shape as
 * EasyCo\Media\ProductMedia, mirrored here with sortOrder dropped:
 * there is no ordering concept for collection membership.
 *
 * IMMUTABLE — beyond assignId() (a structural/ownership reference, not
 * a business fact, same posture ProductMedia's own id takes), there is
 * no mutation method of any kind. A collection assignment is added or removed,
 * never edited in place.
 *
 * NOT ON Product.php: same reasoning that kept ProductMedia out of
 * Product — a pivot association, not this aggregate's concern.
 */
final class ProductCollection
{
    public function __construct(
        private ?string $id,
        private readonly string $productId,
        private readonly string $collectionId,
    ) {
        if ($productId === '') {
            throw new InvalidArgumentException('ProductCollection productId must not be empty.');
        }

        if ($collectionId === '') {
            throw new InvalidArgumentException('ProductCollection collectionId must not be empty.');
        }
    }

    /**
     * Reconstitutes a ProductCollection exactly as it exists in storage.
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
        string $collectionId,
    ): self {
        return new self(
            id: $id,
            productId: $productId,
            collectionId: $collectionId,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ProductCollection already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function collectionId(): string
    {
        return $this->collectionId;
    }
}
