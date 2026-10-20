<?php

namespace App\Content\HtmlBlocks;

use App\Rules\PlainText;
use App\Storefront\Reader\ImageSetBuilder;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Collection;

/**
 * «Снимки с връзки» (MENU-5, menu-editor-v2-design.md "MENU-5 as built"): up to 4 pictures of an HTML block, each `{media_asset_id, alt, url}`. A picture is a Media-domain asset
 * (WebP / variants through ProcessMediaAssetJob, later a media library), its link an optional address of its OWN: `https:` or a path of the shop (`/product-category/boys`);
 * never `javascript:`, `data:`, `http:`, `mailto:` or `//host`. This class holds the rules (one place for the admin form, the model and the tests) and builds the markup; the markup is
 * ALWAYS passed through HtmlBlockSanitizer::render() by the caller (HtmlBlockModel), so the same filter runs on it as on the rich body.
 */
final class HtmlBlockPictures
{
    public const MAX = 4;

    public const ALT_MAX = 200;

    /** The address of a picture: an `https:` address or a path of the shop (starts with one `/`); null = not acceptable. */
    public static function safeUrl(string $value): ?string
    {
        $url = trim($value);

        if ($url === '' || strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'mailto' || (! str_starts_with($url, '/') && preg_match('#^https://#i', $url) !== 1)) {
            return null;
        }

        return HtmlBlockSanitizer::safeLink($url);
    }

    /**
     * The stored list as it is kept: at most MAX items, each with a whole-number asset id, a plain alt and an acceptable address (an unacceptable one is dropped, not kept). What
     * the admin form refused earlier (errors()) never gets here; this is the last line of defence for a script or a hand-made row.
     *
     * @return list<array{media_asset_id: int, alt: string, url: string}>
     */
    public static function clean(mixed $pictures): array
    {
        $out = [];

        foreach (is_array($pictures) ? array_slice(array_values($pictures), 0, self::MAX) : [] as $item) {
            $id = is_array($item) ? ($item['media_asset_id'] ?? null) : null;
            $id = is_string($id) && ctype_digit($id) ? (int) $id : $id;

            if (! is_int($id) || $id < 1) {
                continue;
            }

            $alt = trim((string) ($item['alt'] ?? ''));
            $alt = self::plain($alt) ? mb_substr($alt, 0, self::ALT_MAX) : '';
            $url = self::safeUrl((string) ($item['url'] ?? '')) ?? '';

            $out[] = ['media_asset_id' => $id, 'alt' => $alt, 'url' => $url];
        }

        return $out;
    }

    /**
     * What is wrong with a list, by `{index}.{field}` (empty = fine). Used by the admin form before it saves.
     *
     * @param  array<int, mixed>  $pictures
     * @return array<string, string>
     */
    public static function errors(array $pictures): array
    {
        $errors = [];
        $items = array_values($pictures);

        if (count($items) > self::MAX) {
            $errors['pictures'] = __('html_blocks.errors.too_many', ['max' => self::MAX]);
        }

        $ids = array_filter(array_map(static fn ($item): mixed => is_array($item) && is_int($item['media_asset_id'] ?? null) ? $item['media_asset_id'] : null, $items));
        $known = $ids === [] ? [] : MediaAssetModel::query()->whereIn('id', $ids)->pluck('id')->all();

        foreach ($items as $i => $item) {
            $item = is_array($item) ? $item : [];
            $id = $item['media_asset_id'] ?? null;

            if (! is_int($id) || ! in_array($id, $known, true)) {
                $errors["{$i}.image"] = __('html_blocks.errors.picture_missing');
            }

            $alt = trim((string) ($item['alt'] ?? ''));

            if (mb_strlen($alt) > self::ALT_MAX || ($alt !== '' && ! self::plain($alt))) {
                $errors["{$i}.alt"] = __('html_blocks.errors.alt', ['max' => self::ALT_MAX]);
            }

            $url = trim((string) ($item['url'] ?? ''));

            if ($url !== '' && self::safeUrl($url) === null) {
                $errors["{$i}.url"] = __('html_blocks.errors.url');
            }
        }

        return $errors;
    }

    /**
     * The pictures as markup, in order, one `<figure>` each; a picture whose asset is gone (or has no printable address) is skipped. The picture is the `medium` variant once the asset is
     * READY (with its real width and height), the original file until then. With an address it is wrapped in `<a href>`.
     *
     * @param  list<array<string, mixed>>  $pictures  the stored list
     * @param  Collection<int, MediaAssetModel>|array<int, MediaAssetModel>  $assets  by id
     */
    public static function html(array $pictures, Collection|array $assets): string
    {
        $assets = $assets instanceof Collection ? $assets->all() : $assets;
        $images = app(ImageSetBuilder::class);
        $storage = app(MediaStorageAdapter::class);
        $out = '';

        foreach (self::clean($pictures) as $picture) {
            $asset = $assets[$picture['media_asset_id']] ?? null;

            if (! $asset instanceof MediaAssetModel) {
                continue;
            }

            $alt = $picture['alt'] !== '' ? $picture['alt'] : trim((string) $asset->alt_text);
            $set = $images->build($asset, $alt, '');
            $source = self::local($set !== null ? $set->src : $storage->url((string) $asset->disk, (string) $asset->path));

            if (HtmlBlockSanitizer::safeSource($source) === null) {
                continue;
            }

            $size = $set !== null ? ' width="'.(int) $set->width.'" height="'.(int) $set->height.'"' : '';
            $img = '<img src="'.e($source).'" alt="'.e(mb_substr($alt, 0, self::ALT_MAX)).'"'.$size.'>';
            $out .= '<figure>'.($picture['url'] !== '' ? '<a href="'.e($picture['url']).'">'.$img.'</a>' : $img).'</figure>';
        }

        return $out;
    }

    /** An absolute address of our own storage as the path it is served at (a plain `http://` host would not pass the source filter). */
    private static function local(string $url): string
    {
        return preg_match('#^https?://[^/]+(/storage/.*)$#i', $url, $m) === 1 ? $m[1] : $url;
    }

    private static function plain(string $text): bool
    {
        $failed = false;
        (new PlainText)->validate('alt', $text, static function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }
}
