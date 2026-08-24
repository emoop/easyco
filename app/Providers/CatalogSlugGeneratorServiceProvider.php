<?php

namespace App\Providers;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Extensibility\Hook;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registers the real slug-generation listener on the 'catalog.product.slug'
 * filter — see extensibility-design-and-hooks.md's Hook Reference. Unlike
 * DemoHooksServiceProvider, this is a real, production-intended feature:
 * every Product needs a slug, and this is what actually generates one.
 *
 * IMPORTANT — deliberately NOT ASCII: this follows WordPress's
 * sanitize_title() philosophy, not a Western-web assumption. A Cyrillic or
 * Turkish product name produces a Cyrillic/Turkish slug, never a forced
 * transliteration to Latin. If ASCII transliteration is ever wanted (e.g.
 * for a specific storefront/CDN requirement), it belongs in a SEPARATE,
 * independently-registered listener at a different priority — never
 * folded into this one, so "give me a correct slug in the product's own
 * script" stays available on its own regardless of what else is added
 * later.
 *
 * Registered in boot(), not register() — same ordering reasoning as
 * DemoHooksServiceProvider: EasyCo\Extensibility\HookRegistry's singleton
 * binding (from HookServiceProvider::register()) must already exist
 * before anything resolves it, and Laravel only guarantees that once
 * every provider's register() phase has finished.
 */
class CatalogSlugGeneratorServiceProvider extends ServiceProvider
{
    private const MAX_DEDUP_ATTEMPTS = 50;

    public function boot(): void
    {
        Hook::filter('catalog.product.slug', function (string $value, string $name): string {
            $candidate = $this->cleanup($value !== '' ? $value : $name);

            if ($candidate === '') {
                // Punctuation-/emoji-only input cleans down to nothing —
                // fall back to a generic base rather than handing back an
                // empty slug (which Product::assertValidSlug() rejects
                // anyway).
                $candidate = 'product';
            }

            return $this->deduplicate($candidate);
        });
    }

    /**
     * Unicode-aware lowercasing (mb_strtolower() — never strtolower(),
     * which corrupts non-ASCII bytes) plus normalization down to exactly
     * the alphabet Product::assertValidSlug() accepts: \p{Ll}, \p{M},
     * digits, and single hyphens between segments. Used both for
     * auto-generation from $name and for cleaning up a merchant-supplied
     * manual override — a merchant might type "Червена Рокля" with
     * capitals/spaces expecting it to be cleaned up, not rejected.
     */
    private function cleanup(string $value): string
    {
        $lower = mb_strtolower($value, 'UTF-8');

        // Whitespace runs first, so multiple spaces collapse to one
        // hyphen rather than several.
        $hyphenated = preg_replace('/\s+/u', '-', $lower);

        // Anything else outside the accepted alphabet also becomes a
        // hyphen — punctuation, symbols, emoji, characters
        // mb_strtolower() couldn't fold to a \p{Ll}, etc.
        $hyphenated = preg_replace('/[^\p{Ll}\p{M}\d-]+/u', '-', $hyphenated);

        // Collapse any run of hyphens produced by the replacements above
        // into one, then trim leading/trailing hyphens.
        $collapsed = preg_replace('/-+/', '-', $hyphenated);

        return trim($collapsed, '-');
    }

    /**
     * Queries ProductRepository::findBySlug() and appends an incrementing
     * numeric suffix until an available slug is found, bounded to
     * MAX_DEDUP_ATTEMPTS. This is best-effort only — a concurrent request
     * could still claim the same slug between this check and the actual
     * insert, which is exactly why EloquentProductRepository::save() has
     * its own authoritative, DB-constraint-driven retry as the real
     * safety net (see its saveProductModelWithSlugCollisionRetry()).
     */
    private function deduplicate(string $candidate): string
    {
        $repository = app(ProductRepository::class);

        if ($repository->findBySlug($candidate) === null) {
            return $candidate;
        }

        for ($suffix = 1; $suffix <= self::MAX_DEDUP_ATTEMPTS; $suffix++) {
            $attempt = "{$candidate}-{$suffix}";

            if ($repository->findBySlug($attempt) === null) {
                return $attempt;
            }
        }

        throw new RuntimeException(
            "Could not generate a unique slug for \"{$candidate}\": all of \"{$candidate}-1\" through ".
            '"'.$candidate.'-'.self::MAX_DEDUP_ATTEMPTS.'" are already taken.'
        );
    }
}
