<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\CollectionRepository;
use EasyCo\Catalog\Collection;

/**
 * Maps Collection onto catalog_collections via CollectionModel. P6e-1: identical shape to EloquentSeasonRepository, but a product has MANY collections: the in-use count is the pivot's (a plain count of catalog_product_collections rows; the pair is unique, so it is also the number of products). Identical shape to
 * EloquentBrandRepository's id-or-new + assignId() pattern. A
 * duplicate `slug` is a plain unique-constraint violation that
 * propagates as a raw QueryException — no dedicated exception
 * wrapping, same precedent as every other simple Catalog lookup
 * entity's repository.
 */
final class EloquentCollectionRepository implements CollectionRepository
{
    public function save(Collection $collection): void
    {
        $model = $collection->id() !== null
            ? CollectionModel::findOrFail($collection->id())
            : new CollectionModel();

        $model->name = $collection->name();
        $model->slug = $collection->slug();
        $model->picture_media_asset_id = $collection->pictureMediaAssetId();
        $model->save();

        if ($collection->id() === null) {
            $collection->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Collection
    {
        $model = CollectionModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return Collection[] */
    public function all(): array
    {
        return CollectionModel::all()
            ->map(fn (CollectionModel $model) => $this->toDomain($model))
            ->all();
    }

    public function countProductsUsing(string $collectionId): int
    {
        return ProductCollectionModel::where('collection_id', $collectionId)->count();
    }

    public function delete(string $id): void
    {
        CollectionModel::findOrFail($id)->delete();
    }

    private function toDomain(CollectionModel $model): Collection
    {
        return new Collection(
            id: (string) $model->id,
            name: $model->name,
            slug: $model->slug,
            pictureMediaAssetId: $model->picture_media_asset_id !== null ? (string) $model->picture_media_asset_id : null,
        );
    }
}
