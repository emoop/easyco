<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\ProductCollectionRepository;
use EasyCo\Catalog\Exceptions\CollectionAlreadyAssignedException;
use EasyCo\Catalog\ProductCollection;
use Illuminate\Database\QueryException;

/** Maps the ProductCollection entity onto catalog_product_collections. */
final class EloquentProductCollectionRepository implements ProductCollectionRepository
{
    public function save(ProductCollection $productCollection): void
    {
        $model = $productCollection->id() !== null
            ? ProductCollectionModel::findOrFail($productCollection->id())
            : new ProductCollectionModel();

        $model->product_id = $productCollection->productId();
        $model->collection_id = $productCollection->collectionId();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isProductCollectionUniqueViolation($e)) {
                throw CollectionAlreadyAssignedException::forProduct(
                    $productCollection->productId(),
                    $productCollection->collectionId()
                );
            }

            throw $e;
        }

        if ($productCollection->id() === null) {
            $productCollection->assignId((string) $model->id);
        }
    }

    /**
     * Detects a violation of cpcol_product_collection_unique
     * — the UNIQUE(product_id, collection_id) index from
     * 2026_11_12_000001_create_catalog_collections_tables.php,
     * confirmed via a real SHOW CREATE TABLE against the dev database
     * rather than assumed. SQLSTATE 23000 + driver error code (MySQL
     * 1062 / SQLite 19) is the primary check, then errorInfo[2] narrows
     * to this specific constraint — never $e->getMessage() string
     * matching (CLAUDE.md rule 3, mirrors
     * EloquentProductMediaRepository::isProductMediaUniqueViolation()).
     */
    private function isProductCollectionUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'cpcol_product_collection_unique')
            || str_contains($driverErrorMessage, 'catalog_product_collections.collection_id');
    }

    private function isPossibleUniqueConstraintViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        return $sqlState === '23000' && in_array($driverErrorCode, [1062, 19], true);
    }

    public function remove(string $id): void
    {
        ProductCollectionModel::findOrFail($id)->delete();
    }

    /**
     * @return ProductCollection[] Ordered by id ASC — no ordering concept
     *   exists for collection membership (unlike ProductMedia's sort_order), so
     *   this is simply a consistent, deterministic order rather than one
     *   carrying any domain meaning.
     */
    public function findByProductId(string $productId): array
    {
        return ProductCollectionModel::where('product_id', $productId)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (ProductCollectionModel $model) => $this->toDomainProductCollection($model))
            ->all();
    }

    /**
     * @param string[] $productIds
     * @return ProductCollection[] Ordered by id ASC — same reasoning as
     *   findByProductId() above; the caller groups by productId itself.
     */
    public function findByProductIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductCollectionModel::whereIn('product_id', $productIds)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (ProductCollectionModel $model) => $this->toDomainProductCollection($model))
            ->all();
    }

    private function toDomainProductCollection(ProductCollectionModel $model): ProductCollection
    {
        return ProductCollection::reconstituteFromStorage(
            id: (string) $model->id,
            productId: (string) $model->product_id,
            collectionId: (string) $model->collection_id,
        );
    }
}
