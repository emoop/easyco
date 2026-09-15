<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\ProductGroup;

/**
 * Maps ProductGroup onto catalog_product_groups via ProductGroupModel.
 * Identical shape to EloquentSeasonRepository's id-or-new + assignId()
 * pattern. A duplicate `code` is a plain unique-constraint violation
 * that propagates as a raw QueryException — no dedicated exception
 * wrapping, same precedent as every other simple Catalog lookup
 * entity's repository.
 */
final class EloquentProductGroupRepository implements ProductGroupRepository
{
    public function save(ProductGroup $productGroup): void
    {
        $model = $productGroup->id() !== null
            ? ProductGroupModel::findOrFail($productGroup->id())
            : new ProductGroupModel;

        $model->code = $productGroup->code();
        $model->name = $productGroup->name();
        $model->save();

        if ($productGroup->id() === null) {
            $productGroup->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?ProductGroup
    {
        $model = ProductGroupModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return ProductGroup[] */
    public function all(): array
    {
        return ProductGroupModel::all()
            ->map(fn (ProductGroupModel $model) => $this->toDomain($model))
            ->all();
    }

    private function toDomain(ProductGroupModel $model): ProductGroup
    {
        return new ProductGroup(
            id: (string) $model->id,
            code: $model->code,
            name: $model->name,
        );
    }
}
