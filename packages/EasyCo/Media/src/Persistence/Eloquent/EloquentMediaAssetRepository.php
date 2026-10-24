<?php

namespace EasyCo\Media\Persistence\Eloquent;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaDetails;
use EasyCo\Media\Storage\UploadDetailsStash;
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

        // ML-1a: a NEW row without details takes the ones the upload left (an admin form stores the bytes first and creates the row on save)
        $details = $asset->details() ?? ($asset->id() === null ? UploadDetailsStash::take($asset->disk(), $asset->path()) : null);

        if ($details !== null) {
            $model->original_name = $details->originalName;
            $model->mime = $details->mime;
            $model->size_bytes = $details->sizeBytes;
            $model->width = $details->width;
            $model->height = $details->height;
            $model->uploaded_by = $details->uploadedBy;
        }

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

    public function delete(string $id): void
    {
        MediaAssetModel::destroy($id);
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
            details: $model->original_name === null && $model->mime === null && $model->size_bytes === null && $model->width === null && $model->uploaded_by === null
                ? null
                : new MediaDetails($model->original_name, $model->mime, $model->size_bytes, $model->width, $model->height, $model->uploaded_by),
        );
    }
}
