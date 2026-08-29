<?php

namespace EasyCo\Media\Persistence\Eloquent;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaVariant;

/**
 * Maps the MediaAsset entity onto catalog_media.
 *
 * variants SERIALIZATION: MediaAsset::variants() returns MediaVariant[]
 * (objects) — each is converted to a plain associative array before
 * assignment to $model->variants; MediaAssetModel's 'array' cast then
 * JSON-encodes the whole array automatically on save(). An empty
 * MediaVariant[] (always the case for VIDEO/SOCIAL_VIDEO, §4) is
 * therefore written as JSON '[]', never null — assigning the (possibly
 * empty) mapped array directly, rather than a conditional null, is what
 * guarantees this.
 */
final class EloquentMediaAssetRepository implements MediaAssetRepository
{
    public function save(MediaAsset $asset): void
    {
        $model = $asset->id() !== null
            ? MediaAssetModel::findOrFail($asset->id())
            : new MediaAssetModel();

        $model->type = $asset->type()->value;
        $model->disk = $asset->disk();
        $model->path = $asset->path();
        $model->alt_text = $asset->altText();
        $model->processing_status = $asset->processingStatus()->value;
        $model->processing_failure_reason = $asset->processingFailureReason();
        $model->variants = array_map(
            fn (MediaVariant $variant) => [
                'tier' => $variant->tier,
                'width' => $variant->width,
                'height' => $variant->height,
                'quality' => $variant->quality,
                'path' => $variant->path,
            ],
            $asset->variants()
        );

        $model->save();

        if ($asset->id() === null) {
            $asset->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?MediaAsset
    {
        $model = MediaAssetModel::find($id);

        return $model !== null ? $this->toDomainAsset($model) : null;
    }

    private function toDomainAsset(MediaAssetModel $model): MediaAsset
    {
        $variants = array_map(
            fn (array $row) => new MediaVariant($row['tier'], $row['width'], $row['height'], $row['quality'], $row['path']),
            $model->variants ?? []
        );

        return MediaAsset::reconstituteFromStorage(
            id: (string) $model->id,
            type: MediaType::from($model->type),
            disk: $model->disk,
            path: $model->path,
            altText: $model->alt_text,
            processingStatus: ProcessingStatus::from($model->processing_status),
            processingFailureReason: $model->processing_failure_reason,
            variants: $variants,
        );
    }
}
