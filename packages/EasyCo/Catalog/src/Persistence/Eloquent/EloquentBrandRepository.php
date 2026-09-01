<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;

/**
 * Maps Brand onto catalog_brands via BrandModel. Mirrors
 * EloquentAttributeDefinitionRepository's id-or-new + assignId() pattern
 * exactly. A duplicate `slug` is a plain unique-constraint violation
 * that propagates as a raw QueryException — no dedicated exception
 * wrapping, same precedent EloquentAttributeDefinitionRepository sets
 * for `code` (Brand has no generated-candidate/retry concept the way
 * Product's slug does).
 */
final class EloquentBrandRepository implements BrandRepository
{
    public function save(Brand $brand): void
    {
        $model = $brand->id() !== null
            ? BrandModel::findOrFail($brand->id())
            : new BrandModel();

        $model->name = $brand->name();
        $model->slug = $brand->slug();
        $model->save();

        if ($brand->id() === null) {
            $brand->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Brand
    {
        $model = BrandModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return Brand[] */
    public function all(): array
    {
        return BrandModel::all()
            ->map(fn (BrandModel $model) => $this->toDomain($model))
            ->all();
    }

    private function toDomain(BrandModel $model): Brand
    {
        return new Brand(
            id: (string) $model->id,
            name: $model->name,
            slug: $model->slug,
        );
    }
}
