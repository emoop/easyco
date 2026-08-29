<?php

namespace EasyCo\Media\Persistence\Eloquent;

use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\VariationMedia;

/** Maps the VariationMedia entity onto catalog_variation_media. */
final class EloquentVariationMediaRepository implements VariationMediaRepository
{
    public function save(VariationMedia $variationMedia): void
    {
        $model = $variationMedia->id() !== null
            ? VariationMediaModel::findOrFail($variationMedia->id())
            : new VariationMediaModel();

        $model->variation_id = $variationMedia->variationId();
        $model->media_id = $variationMedia->mediaId();
        $model->sort_order = $variationMedia->sortOrder();

        $model->save();

        if ($variationMedia->id() === null) {
            $variationMedia->assignId((string) $model->id);
        }
    }

    public function remove(string $id): void
    {
        VariationMediaModel::findOrFail($id)->delete();
    }

    /** @return VariationMedia[] */
    public function findByVariationId(string $variationId): array
    {
        return VariationMediaModel::where('variation_id', $variationId)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(fn (VariationMediaModel $model) => $this->toDomainVariationMedia($model))
            ->all();
    }

    public function countByVariationId(string $variationId): int
    {
        return VariationMediaModel::where('variation_id', $variationId)->count();
    }

    private function toDomainVariationMedia(VariationMediaModel $model): VariationMedia
    {
        return VariationMedia::reconstituteFromStorage(
            id: (string) $model->id,
            variationId: (string) $model->variation_id,
            mediaId: (string) $model->media_id,
            sortOrder: $model->sort_order,
        );
    }
}
