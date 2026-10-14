<?php

namespace App\Storefront\Pages;

use App\Content\Models\PageModel;

/**
 * What the storefront reads of the content pages (pages-design.md section 4), in ONE query per request.
 *
 * `published()` loads the light columns (no body) of EVERY published page once and keeps them for the request (the class is bound
 * `scoped`): the footer is a filter of that list, the checkout's "is the selected terms page published, and at which revision" is a
 * lookup in it, and the page selects of the settings are a map of it. A shop has a handful of pages, so the list is small by nature;
 * the footer is on every storefront page, so its cost is pinned by a test (one query, and none on a second read).
 *
 * The BODY is read only by `findPublishedBySlug()`, for the one page being shown.
 */
final class PageReader
{
    /** @var list<object>|null objects with id, slug, title, revision, in_footer, footer_order */
    private ?array $published = null;

    /**
     * Every published page, light columns only, in footer order (footer_order, then title).
     *
     * @return list<object>
     */
    public function published(): array
    {
        return $this->published ??= PageModel::query()
            ->where('status', PageModel::PUBLISHED)
            ->orderBy('footer_order')
            ->orderBy('title')
            ->get(['id', 'slug', 'title', 'revision', 'in_footer', 'footer_order'])
            ->map(fn (PageModel $page): object => (object) $page->only(['id', 'slug', 'title', 'revision', 'in_footer', 'footer_order']))
            ->all();
    }

    /**
     * The footer links: published pages flagged for the footer, by footer_order then title.
     *
     * @return list<array{title: string, url: string}>
     */
    public function footerLinks(): array
    {
        $links = [];

        foreach ($this->published() as $page) {
            if ((bool) $page->in_footer) {
                $links[] = ['title' => (string) $page->title, 'url' => self::urlFor((string) $page->slug)];
            }
        }

        return $links;
    }

    /** One published page (light columns) by id, from the request's list: null when it is a draft, archived or unknown. */
    public function publishedById(int $id): ?object
    {
        foreach ($this->published() as $page) {
            if ((int) $page->id === $id) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The published pages as a select's options: id => title.
     *
     * @return array<int, string>
     */
    public function publishedOptions(): array
    {
        $options = [];

        foreach ($this->published() as $page) {
            $options[(int) $page->id] = (string) $page->title;
        }

        return $options;
    }

    /** The page to SHOW at /info/{slug}: published only (a draft and an unknown slug are the same null). */
    public function findPublishedBySlug(string $slug): ?PageModel
    {
        if ($slug === '' || mb_strlen($slug) > 120) {
            return null;
        }

        return PageModel::query()->where('slug', $slug)->where('status', PageModel::PUBLISHED)->first();
    }

    /** The site-relative url of a page: `/info/{slug}` (percent-encoded for a non-Latin slug). */
    public static function urlFor(string $slug): string
    {
        return route('storefront.page', ['slug' => $slug], false);
    }
}
