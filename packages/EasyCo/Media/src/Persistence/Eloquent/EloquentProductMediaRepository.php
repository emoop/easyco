<?php

namespace EasyCo\Media\Persistence\Eloquent;

use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\ProductMedia;

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

        $model->save();

        if ($productMedia->id() === null) {
            $productMedia->assignId((string) $model->id);
        }
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
