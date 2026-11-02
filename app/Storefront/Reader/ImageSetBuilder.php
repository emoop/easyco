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

    /**
     * SIZES-1: the `sizes` of a product card in the CATEGORY grid, from the PUBLISHED column counts (desktop 3–5, phone 1–2; the tablet is always 3). Pure: only the validated integers reach the string (a
     * value outside the closed lists is today's 4 / 2), so no free text can get into the attribute.
     *
     * Where it comes from (storefront.css): the grid is `.sf-main`'s width = 100vw − 2 × the page padding; below 40rem the gap is 0.75rem, from 40rem 1rem; the columns are the phone's below 48rem, 3 from 48rem
     * (tablet), the desktop's from 64rem. A card is (container − (n − 1) gaps) / n. The page padding is a design setting (a `clamp` the merchant can change, and a CSS variable that `sizes` cannot read), so the
     * formula takes the SMALLEST padding the Design screen allows (8px a side = 1rem in all): the container is never narrower than that, so the value is never SMALLER than the real card (the browser never picks
     * a blurry image) and is rounded UP by up to a few vw for the usual padding. The side-panel layout has narrower cards still: also safe.
     */
    public static function cardSizes(int $cols = 4, int $colsPhone = 2): string
    {
        $cols = in_array($cols, [3, 4, 5], true) ? $cols : 4;
        $colsPhone = in_array($colsPhone, [1, 2], true) ? $colsPhone : 2;

        $calc = static fn (float $spare, int $columns): string => 'calc((100vw - '.rtrim(rtrim(number_format($spare, 4, '.', ''), '0'), '.').'rem) / '.$columns.')';

        return implode(', ', [
            '(min-width: 64rem) '.$calc(1 + ($cols - 1) * 1.0, $cols),
            '(min-width: 48rem) '.$calc(1 + 2 * 1.0, 3),
            '(min-width: 40rem) '.$calc(1 + ($colsPhone - 1) * 1.0, $colsPhone),
            $calc(1 + ($colsPhone - 1) * 0.75, $colsPhone),
        ]);
    }

    public function __construct(
        private readonly MediaStorageAdapter $storage,
    ) {
    }

    /** The public URL of the asset's own file (a video has no renditions: this is the file itself). */
    public function fileUrl(MediaAssetModel $asset): string
    {
        return $this->storage->url((string) $asset->disk, (string) $asset->path);
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
