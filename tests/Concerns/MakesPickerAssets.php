<?php

namespace Tests\Concerns;

use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;

/** ML-3: ready media assets for tests of the media picker fields (ids, not uploaded files). */
trait MakesPickerAssets
{
    /** A READY picture row in the library, big enough for a product photo unless told otherwise. */
    protected function pickerAsset(array $override = []): MediaAssetModel
    {
        $uuid = uniqid('', true);
        $variants = array_map(fn (string $tier): array => ['tier' => $tier, 'width' => 100, 'height' => 100, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-{$tier}.webp"], ['thumbnail', 'medium', 'large']);

        return MediaAssetModel::query()->create($override + ['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/{$uuid}.jpg", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "{$uuid}.jpg", 'size_bytes' => 2048, 'width' => 800, 'height' => 800]);
    }

    /** A READY video row (videos have no pipeline: ready, no variants). */
    protected function pickerVideo(array $override = []): MediaAssetModel
    {
        $uuid = uniqid('', true);

        return MediaAssetModel::query()->create($override + ['type' => 'video', 'disk' => 'public', 'path' => "uploads/2026/10/{$uuid}.mp4", 'processing_status' => 'ready', 'variants' => [], 'original_name' => "{$uuid}.mp4", 'size_bytes' => 4096]);
    }
}
