<?php

namespace EasyCo\Media\Persistence\Eloquent;

use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaAlreadyAttachedException;
use EasyCo\Media\ProductMedia;
use Illuminate\Database\QueryException;

/** Maps the ProductMedia entity onto catalog_product_media. */
final class EloquentProductMediaRepository implements ProductMediaRepository
{
    public function save(ProductMedia $productMedia): void
    {
        $model = $productMedia->id() !== null
            ? ProductMediaModel::findOrFail($productMedia->id())
            : new ProductMediaModel();

        $model->product_id = $productMedia->productId();
        $model->media_id = $productMedia->mediaId();
        $model->sort_order = $productMedia->sortOrder();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isProductMediaUniqueViolation($e)) {
                throw MediaAlreadyAttachedException::forProduct(
                    $productMedia->productId(),
                    $productMedia->mediaId()
                );
            }

            throw $e;
        }

        if ($productMedia->id() === null) {
            $productMedia->assignId((string) $model->id);
        }
    }

    /**
     * Detects a violation of catalog_product_media_product_id_media_id_unique
     * — the UNIQUE(product_id, media_id) index from
     * 2026_08_23_000012_create_catalog_media_tables.php, confirmed via a
     * real SHOW CREATE TABLE against the dev database rather than
     * assumed. SQLSTATE 23000 + driver error code (MySQL 1062 / SQLite
     * 19) is the primary check, then errorInfo[2] narrows to this
     * specific constraint — never $e->getMessage() string matching
     * (CLAUDE.md rule 3, mirrors
     * EloquentProductRepository::isVariationSignatureUniqueViolation()).
     */
    private function isProductMediaUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'catalog_product_media_product_id_media_id_unique')
            || str_contains($driverErrorMessage, 'catalog_product_media.media_id');
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
        ProductMediaModel::findOrFail($id)->delete();
    }

    /** @return ProductMedia[] */
    public function findByProductId(string $productId): array
    {
        return ProductMediaModel::where('product_id', $productId)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(fn (ProductMediaModel $model) => $this->toDomainProductMedia($model))
            ->all();
    }

    public function countByProductId(string $productId): int
    {
        return ProductMediaModel::where('product_id', $productId)->count();
    }

    private function toDomainProductMedia(ProductMediaModel $model): ProductMedia
    {
        return ProductMedia::reconstituteFromStorage(
            id: (string) $model->id,
            productId: (string) $model->product_id,
            mediaId: (string) $model->media_id,
            sortOrder: $model->sort_order,
        );
    }
}
