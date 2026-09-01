<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Exceptions\CategoryAlreadyAssignedException;
use EasyCo\Catalog\ProductCategory;
use Illuminate\Database\QueryException;

/** Maps the ProductCategory entity onto catalog_product_categories. */
final class EloquentProductCategoryRepository implements ProductCategoryRepository
{
    public function save(ProductCategory $productCategory): void
    {
        $model = $productCategory->id() !== null
            ? ProductCategoryModel::findOrFail($productCategory->id())
            : new ProductCategoryModel();

        $model->product_id = $productCategory->productId();
        $model->category_id = $productCategory->categoryId();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isProductCategoryUniqueViolation($e)) {
                throw CategoryAlreadyAssignedException::forProduct(
                    $productCategory->productId(),
                    $productCategory->categoryId()
                );
            }

            throw $e;
        }

        if ($productCategory->id() === null) {
            $productCategory->assignId((string) $model->id);
        }
    }

    /**
     * Detects a violation of
     * catalog_product_categories_product_id_category_id_unique — the
     * UNIQUE(product_id, category_id) index from
     * 2026_08_23_000013_create_catalog_product_taxonomy_pivots.php,
     * confirmed via a real SHOW CREATE TABLE against the dev database
     * rather than assumed. SQLSTATE 23000 + driver error code (MySQL
     * 1062 / SQLite 19) is the primary check, then errorInfo[2] narrows
     * to this specific constraint — never $e->getMessage() string
     * matching (CLAUDE.md rule 3, mirrors
     * EloquentProductMediaRepository::isProductMediaUniqueViolation()).
     */
    private function isProductCategoryUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'catalog_product_categories_product_id_category_id_unique')
            || str_contains($driverErrorMessage, 'catalog_product_categories.category_id');
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
        ProductCategoryModel::findOrFail($id)->delete();
    }

    /**
     * @return ProductCategory[] Ordered by id ASC — no ordering concept
     *   exists for category membership (unlike ProductMedia's
     *   sort_order), so this is simply a consistent, deterministic order
     *   rather than one carrying any domain meaning.
     */
    public function findByProductId(string $productId): array
    {
        return ProductCategoryModel::where('product_id', $productId)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (ProductCategoryModel $model) => $this->toDomainProductCategory($model))
            ->all();
    }

    private function toDomainProductCategory(ProductCategoryModel $model): ProductCategory
    {
        return ProductCategory::reconstituteFromStorage(
            id: (string) $model->id,
            productId: (string) $model->product_id,
            categoryId: (string) $model->category_id,
        );
    }
}
