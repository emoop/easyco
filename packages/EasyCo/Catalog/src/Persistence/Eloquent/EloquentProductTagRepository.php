<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Exceptions\TagAlreadyAssignedException;
use EasyCo\Catalog\ProductTag;
use Illuminate\Database\QueryException;

/** Maps the ProductTag entity onto catalog_product_tags. */
final class EloquentProductTagRepository implements ProductTagRepository
{
    public function save(ProductTag $productTag): void
    {
        $model = $productTag->id() !== null
            ? ProductTagModel::findOrFail($productTag->id())
            : new ProductTagModel();

        $model->product_id = $productTag->productId();
        $model->tag_id = $productTag->tagId();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isProductTagUniqueViolation($e)) {
                throw TagAlreadyAssignedException::forProduct(
                    $productTag->productId(),
                    $productTag->tagId()
                );
            }

            throw $e;
        }

        if ($productTag->id() === null) {
            $productTag->assignId((string) $model->id);
        }
    }

    /**
     * Detects a violation of catalog_product_tags_product_id_tag_id_unique
     * — the UNIQUE(product_id, tag_id) index from
     * 2026_08_23_000013_create_catalog_product_taxonomy_pivots.php,
     * confirmed via a real SHOW CREATE TABLE against the dev database
     * rather than assumed. SQLSTATE 23000 + driver error code (MySQL
     * 1062 / SQLite 19) is the primary check, then errorInfo[2] narrows
     * to this specific constraint — never $e->getMessage() string
     * matching (CLAUDE.md rule 3, mirrors
     * EloquentProductMediaRepository::isProductMediaUniqueViolation()).
     */
    private function isProductTagUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'catalog_product_tags_product_id_tag_id_unique')
            || str_contains($driverErrorMessage, 'catalog_product_tags.tag_id');
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
        ProductTagModel::findOrFail($id)->delete();
    }

    /**
     * @return ProductTag[] Ordered by id ASC — no ordering concept
     *   exists for tag membership (unlike ProductMedia's sort_order), so
     *   this is simply a consistent, deterministic order rather than one
     *   carrying any domain meaning.
     */
    public function findByProductId(string $productId): array
    {
        return ProductTagModel::where('product_id', $productId)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (ProductTagModel $model) => $this->toDomainProductTag($model))
            ->all();
    }

    private function toDomainProductTag(ProductTagModel $model): ProductTag
    {
        return ProductTag::reconstituteFromStorage(
            id: (string) $model->id,
            productId: (string) $model->product_id,
            tagId: (string) $model->tag_id,
        );
    }
}
