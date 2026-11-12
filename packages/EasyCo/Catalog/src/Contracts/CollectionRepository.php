<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Collection;

/**
 * Persistence contract for the global, reusable Collection set — identical
 * shape to BrandRepository. Collection is not owned by any single Product,
 * so there is no aggregate-wide transaction concern here.
 */
interface CollectionRepository
{
    public function save(Collection $collection): void;

    public function findById(string $id): ?Collection;

    /** @return Collection[] */
    public function all(): array;

    /**
     * P6e-1: how many products are in this Collection (rows of the pivot catalog_product_collections; the pair is unique). A Collection is deleted together with its pivot rows (cascade), like a Tag.
     */
    public function countProductsUsing(string $collectionId): int;

    /** Plain infrastructure-level delete — see BrandRepository::delete()'s identical reasoning. */
    public function delete(string $id): void;
}
