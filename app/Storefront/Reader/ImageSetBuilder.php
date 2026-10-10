<?php

namespace App\Storefront\Reader;

use App\Storefront\ReadModels\ImageSet;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;

/**
 * Builds an ImageSet from a loaded media asset (storefront-design.md §4, basic form).
 *
 * The asset's renditions are its `variants` (a JSON list on `catalog_media`, each {tier, width, height, quality,
 * path}), written by ProcessMediaAssetJob; they exist only once processing finished. Only an asset with
 * processing_status = ready is used, and only the customer tiers (`thumbnail`, `medium`, `large`; `admin_grid` is a
 * 42-pixel admin crop and never reaches a customer). Widths and heights are the variant rows' real values: nothing is
 * computed or upscaled. An asset with no usable variant has no ImageSet (null).
 */
final class ImageSetBuilder
{
    public const TIERS = ['thumbnail', 'medium', 'large'];

    public const SIZES_CARD = '(min-width: 1024px) 25vw, (min-width: 640px) 50vw, 100vw';

    public const SIZES_GALLERY = '(min-width: 1024px) 50vw, 100vw';

    private const DEFAULT_TIER = 'medium';

    public function __construct(
        private readonly MediaStorageAdapter $storage,
    ) {
    }

    public function build(MediaAssetModel $asset, string $fallbackAlt, string $sizesHint): ?ImageSet
    {
        if ($asset->processing_status !== 'ready' || $asset->type !== 'image') {
            return null;
        }

        $variants = [];

        foreach ((array) $asset->variants as $row) {
            if (! is_array($row)
                || ! in_array($row['tier'] ?? null, self::TIERS, true)
                || ! is_int($row['width'] ?? null) || $row['width'] < 1
                || ! is_int($row['height'] ?? null) || $row['height'] < 1
                || ! is_string($row['path'] ?? null) || $row['path'] === ''
            ) {
                continue;
            }

            // One rendition per width: a duplicate width would be a redundant srcset entry.
            $variants[$row['width']] ??= $row;
        }

        if ($variants === []) {
            return null;
        }

        ksort($variants);

        $default = null;

        foreach ($variants as $variant) {
            if ($variant['tier'] === self::DEFAULT_TIER) {
                $default = $variant;
            }
        }

        $default ??= end($variants);

        $alt = $asset->alt_text !== null && trim((string) $asset->alt_text) !== '' ? (string) $asset->alt_text : $fallbackAlt;

        return new ImageSet(
            src: $this->storage->url((string) $asset->disk, $default['path']),
            alt: $alt,
            width: $default['width'],
            height: $default['height'],
            srcset: array_values(array_map(
                fn (array $variant): array => ['url' => $this->storage->url((string) $asset->disk, $variant['path']), 'width' => $variant['width']],
                $variants,
            )),
            sizesHint: $sizesHint,
        );
    }
}
