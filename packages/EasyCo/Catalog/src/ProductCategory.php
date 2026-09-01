<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;
use LogicException;

/**
 * One product's assignment to one Category — the domain class behind
 * `catalog_product_categories`. `categoryId` is a plain string id
 * (Category::id()), not a Category instance — same pivot-association
 * shape as EasyCo\Media\ProductMedia, mirrored here with sortOrder
 * dropped: there is no ordering concept for category membership.
 *
 * IMMUTABLE — beyond assignId() (a structural/ownership reference, not
 * a business fact, same posture ProductMedia's own id takes), there is
 * no mutation method of any kind. A category assignment is added or
 * removed, never edited in place.
 *
 * NOT ON Product.php: same reasoning that kept ProductMedia out of
 * Product — a pivot association, not this aggregate's concern.
 */
final class ProductCategory
{
    public function __construct(
        private ?string $id,
        private readonly string $productId,
        private readonly string $categoryId,
    ) {
        if ($productId === '') {
            throw new InvalidArgumentException('ProductCategory productId must not be empty.');
        }

        if ($categoryId === '') {
            throw new InvalidArgumentException('ProductCategory categoryId must not be empty.');
        }
    }

    /**
     * Reconstitutes a ProductCategory exactly as it exists in storage.
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
        string $categoryId,
    ): self {
        return new self(
            id: $id,
            productId: $productId,
            categoryId: $categoryId,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ProductCategory already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function categoryId(): string
    {
        return $this->categoryId;
    }
}
