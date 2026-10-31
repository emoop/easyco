<?php

namespace App\Content\HtmlBlocks;

use App\Storefront\Reader\ImageSetBuilder;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;

/**
 * HB-4: the background picture of a block's style. A style keeps only the MEDIA ID of a picture of the library; the address that goes into the stylesheet is resolved HERE, at compile time, from the
 * library's own record: the largest customer rendition once the asset is READY (the WebP variants), the original file until then. Only a path of our own storage that matches URL_PATH ever leaves this class;
 * HtmlBlockStyle::isValidRule() re-checks the very same pattern, so nothing else can ride along in `url(…)`. A picture that is gone (deleted by force, not an image, no printable address) resolves to
 * nothing and the block compiles WITHOUT its picture keys.
 */
final class HtmlBlockBackground
{
    /**
     * The shape of the address inside `url(...)`: a path under `/storage/`, made of letters, digits, dot, dash, underscore and slash only (no quote, bracket, backslash, whitespace, semicolon, colon, `..`
     * or `//` can be in it) and ending in a picture extension. One source for the resolver and for the rule's validator.
     */
    public const URL_PATH = '/storage/(?![A-Za-z0-9._/-]*\.\.)(?![A-Za-z0-9._/-]*//)[A-Za-z0-9][A-Za-z0-9._/-]{0,250}\.(?i:webp|jpe?g|png|gif|avif)';

    public static function isLibraryPath(string $url): bool
    {
        return preg_match('~\A'.self::URL_PATH.'\z~', $url) === 1;
    }

    /**
     * The addresses of pictures by id (ONE query for all). Only the usable ones are in the result.
     *
     * @param  list<int>  $ids
     * @return array<int, string> id => `/storage/…` path
     */
    public static function urls(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $images = app(ImageSetBuilder::class);
        $storage = app(MediaStorageAdapter::class);
        $out = [];

        foreach (MediaAssetModel::query()->whereIn('id', $ids)->where('type', 'image')->get() as $asset) {
            $set = $images->build($asset, '', '');
            // the renditions are ordered by width: the last one is the largest (a banner is wide)
            $renditions = $set !== null ? $set->srcset : [];
            $source = $renditions !== [] ? (string) $renditions[array_key_last($renditions)]['url'] : $storage->url((string) $asset->disk, (string) $asset->path);
            $path = preg_match('#^https?://[^/]+(/storage/.*)$#i', $source, $m) === 1 ? $m[1] : $source;

            self::isLibraryPath($path) && $out[(int) $asset->id] = $path;
        }

        return $out;
    }

    public static function urlFor(int $id): ?string
    {
        return self::urls([$id])[$id] ?? null;
    }

    /** Is this the id of a picture of the library (an image, not a video)? The form asks; the stylesheet compiler simply gets no address for anything else. */
    public static function isPicture(int $id): bool
    {
        return $id > 0 && MediaAssetModel::query()->whereKey($id)->where('type', 'image')->exists();
    }
}
