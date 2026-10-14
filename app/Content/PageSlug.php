<?php

namespace App\Content;

/**
 * THE slug rule of a content page (pages-design.md 3.2), one place: lower-case, letters of ANY script, digits and single hyphens,
 * no leading or trailing hyphen, 1 to 120 characters. The database holds the same rule as `pages_slug_check`. The `/info/` prefix
 * belongs to pages alone, so no word is reserved.
 */
final class PageSlug
{
    public const MAX_LENGTH = 120;

    /** A valid slug, by the rule above (the caller still checks uniqueness). */
    public static function isValid(string $slug): bool
    {
        return mb_strlen($slug) >= 1
            && mb_strlen($slug) <= self::MAX_LENGTH
            && preg_match('/^[\p{L}\p{N}]+(-[\p{L}\p{N}]+)*$/u', $slug) === 1
            && $slug === mb_strtolower($slug);
    }

    /** What the form suggests for a title (and what a typed slug is cleaned to): lower-case, every run of other characters one hyphen. */
    public static function suggest(string $text): string
    {
        $slug = mb_strtolower(trim($text));
        $slug = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        $slug = trim($slug, '-');

        return rtrim(mb_substr($slug, 0, self::MAX_LENGTH), '-');
    }
}
