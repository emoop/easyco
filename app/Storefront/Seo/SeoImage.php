<?php

namespace App\Storefront\Seo;

use App\Storefront\ReadModels\ImageSet;

/** SEO-1: the picture choices of the share cards, the JSON-LD and the sitemap, from an ImageSet the read layer already built (no query). */
final class SeoImage
{
    /** An absolute URL: one that already has a scheme is kept (a CDN disk), a site-relative one gets the shop's address. */
    public static function absolute(string $url): string
    {
        return preg_match('#^https?://#i', $url) === 1 ? $url : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /** The largest stored rendition (the srcset is ascending by width; with none, the default `src`). */
    public static function largest(ImageSet $image): string
    {
        $last = $image->srcset === [] ? null : $image->srcset[array_key_last($image->srcset)];

        return self::absolute($last['url'] ?? $image->src);
    }

    /**
     * The stored rendition whose width is closest to `$target` (1200 for a share card): url, width and the height in proportion to the default rendition's (the stored renditions are scaled copies).
     *
     * @return array{url: string, width: int, height: int}
     */
    public static function closest(ImageSet $image, int $target = 1200): array
    {
        $best = ['url' => $image->src, 'width' => $image->width];

        foreach ($image->srcset as $variant) {
            if (abs($variant['width'] - $target) < abs($best['width'] - $target)) {
                $best = ['url' => $variant['url'], 'width' => $variant['width']];
            }
        }

        $height = $image->width > 0 ? (int) round($best['width'] * $image->height / $image->width) : $image->height;

        return ['url' => self::absolute($best['url']), 'width' => $best['width'], 'height' => max(1, $height)];
    }
}
