<?php

namespace App\Storefront\Url;

/**
 * The ONE place a storefront URL is built from a slug or a path (storefront-design.md §5, storefront-frontend-design.md
 * §7): Blade, the sitemap and the API never concatenate one themselves.
 *
 * URLs are ROOT-RELATIVE paths (no host: the host is the deployment's, and golden files stay deterministic) and every
 * segment is percent-encoded, so a native-script slug such as `лятна-рокля` is a valid href (a browser shows it decoded).
 *
 * The same class owns the input rules for slugs and paths, so what a URL may contain is defined once: a segment is
 * `[\p{L}\p{M}\p{N}-]` (letters, combining marks, digits, hyphen: what the slug generator emits), 1 to 200 characters;
 * a path has 1 to 8 segments. Anything else is refused BEFORE any query.
 */
final class StorefrontUrls
{
    public const MAX_SEGMENTS = 8;

    public const MAX_SEGMENT_LENGTH = 200;

    public function product(string $slug): string
    {
        return '/product/'.rawurlencode($slug);
    }

    /** @param string $path the canonical category path, slugs joined by "/" */
    public function category(string $path): string
    {
        return '/product-category/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public function tag(string $slug): string
    {
        return '/product-tag/'.rawurlencode($slug);
    }

    public function brand(string $slug): string
    {
        return '/brand/'.rawurlencode($slug);
    }

    public function isValidSlug(string $slug): bool
    {
        return $slug !== ''
            && mb_strlen($slug, 'UTF-8') <= self::MAX_SEGMENT_LENGTH
            && preg_match('/^[\p{L}\p{M}\p{N}-]+$/Du', $slug) === 1;
    }

    /**
     * The segments of a category path, or null when the path is not acceptable (empty, more than 8 segments, an
     * empty segment, a segment over 200 characters or with a character outside the slug alphabet). A single leading
     * or trailing "/" is tolerated; nothing is decoded here (the router has decoded it once already).
     *
     * @return list<string>|null
     */
    public function pathSegments(string $path): ?array
    {
        $path = trim($path, '/');

        if ($path === '' || strlen($path) > self::MAX_SEGMENTS * (self::MAX_SEGMENT_LENGTH * 4 + 1)) {
            return null;
        }

        $segments = explode('/', $path);

        if (count($segments) > self::MAX_SEGMENTS) {
            return null;
        }

        foreach ($segments as $segment) {
            if (! $this->isValidSlug($segment)) {
                return null;
            }
        }

        return $segments;
    }
}
