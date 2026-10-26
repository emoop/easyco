<?php

namespace App\Storefront\Design;

use App\Storefront\Header\HeaderCatalog;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;

/**
 * THE LOGO PICTURE (LG-1): what may be a logo and which rendition of it is printed. The two questions that need the library (a query) live here and nowhere in the validators, because the storefront
 * runs those validators on every read and must never query: the Design screen asks `problem()` when a draft is saved, the compilers ask `rendition()` at publish and in the preview and put the answer
 * ({url, width, height}) into the compiled payload. The views only print it. A picture that is missing, not READY (no variants), not an `image` or not a PNG / WebP — decided by the stored
 * `catalog_media.mime`, never the file name — gives no rendition, and the logo silently falls back to the static file.
 */
final class LogoImage
{
    /** The WebP size variants a logo may be printed from (the 42px admin crop is never one). */
    private const TIERS = ['thumbnail', 'medium', 'large'];

    /** The logo is shown at least this many times sharper than its CSS size (phones with a 2x screen). */
    public const SHARPNESS = 2;

    /** @return list<string> */
    public static function mimes(): array
    {
        return HeaderCatalog::LOGO_IMAGE['mimes'];
    }

    /** The message of why this picture cannot be a logo, or null when it can (the Design screen shows it on the field at save time). */
    public static function problem(int $id): ?string
    {
        $row = MediaAssetModel::query()->find($id);

        return self::problemOf($row);
    }

    /**
     * The printed rendition: the smallest WebP variant whose width is at least SHARPNESS times the width the logo is SHOWN at (its height is the setting, its width follows the picture's proportions),
     * else the largest variant. Null when the picture cannot be a logo (see the class docblock).
     *
     * @return array{url: string, width: int, height: int}|null
     */
    public static function rendition(int $id, int $displayHeight): ?array
    {
        $row = MediaAssetModel::query()->find($id);

        if (self::problemOf($row) !== null) {
            return null;
        }

        $variants = self::variants($row);

        if ($variants === []) {
            return null;
        }

        usort($variants, static fn (array $a, array $b): int => $a['width'] <=> $b['width']);
        $largest = $variants[array_key_last($variants)];
        $shownWidth = max(1, $displayHeight) * $largest['width'] / $largest['height'];
        $needed = (int) ceil(self::SHARPNESS * $shownWidth);
        $chosen = $largest;

        foreach ($variants as $variant) {
            if ($variant['width'] >= $needed) {
                $chosen = $variant;

                break;
            }
        }

        return ['url' => app(MediaStorageAdapter::class)->url((string) $row->disk, $chosen['path']), 'width' => $chosen['width'], 'height' => $chosen['height']];
    }

    private static function problemOf(?MediaAssetModel $row): ?string
    {
        if ($row === null) {
            return (string) __('design.logo.errors.missing');
        }

        if ($row->type !== 'image') {
            return (string) __('design.logo.errors.not_picture');
        }

        if ($row->mime === null || $row->mime === '') {
            return (string) __('design.logo.errors.unknown_type');
        }

        if (! in_array($row->mime, self::mimes(), true)) {
            return (string) __('design.logo.errors.wrong_type');
        }

        if ($row->missing_at !== null || $row->processing_status !== 'ready' || self::variants($row) === []) {
            return (string) __('design.logo.errors.not_ready');
        }

        return null;
    }

    /** @return list<array{tier: string, width: int, height: int, path: string}> the usable size variants */
    private static function variants(MediaAssetModel $row): array
    {
        $variants = is_string($row->variants) ? json_decode($row->variants, true) : $row->variants;
        $out = [];

        foreach (is_array($variants) ? $variants : [] as $variant) {
            if (is_array($variant) && in_array($variant['tier'] ?? null, self::TIERS, true) && is_int($variant['width'] ?? null) && is_int($variant['height'] ?? null)
                && $variant['width'] > 0 && $variant['height'] > 0 && is_string($variant['path'] ?? null) && $variant['path'] !== '') {
                $out[] = ['tier' => $variant['tier'], 'width' => $variant['width'], 'height' => $variant['height'], 'path' => $variant['path']];
            }
        }

        return $out;
    }
}
