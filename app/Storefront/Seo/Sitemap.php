<?php

namespace App\Storefront\Seo;

use App\Content\Models\PageModel;
use App\Storefront\Index\SubjectIndex;
use App\Storefront\Pages\PageReader;
use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\ReadModels\CategoryNode;
use App\Storefront\Reader\CatalogReader;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The sitemap (SEO-1, seo.md): an INDEX and three kinds of child file. Every file is built from the same visibility rules as the page it lists (a page that would be a 404 or is noindex is never listed), has a
 * constant number of statements whatever the number of rows (one chunk of products is ONE page of 5,000, read with its first picture in two statements) and is kept in the cache store for ONE HOUR (no purge yet: P8).
 *
 *   sitemap.xml                 the index, with `<lastmod>` for pages and categories (the newest `updated_at`; the product chunks have none: a per-chunk date would cost a query per chunk)
 *   sitemap/pages.xml           the home page and every published content page that is not noindex
 *   sitemap/categories.xml      every category the storefront shows (no filtered url, no `?page=`)
 *   sitemap/products-{n}.xml    the visible products by id in chunks of `storefront.sitemap.chunk` (5,000); with `<image:image>` of the main picture at its largest stored size
 *
 * `<loc>` is the canonical URL: the read layer's own percent-encoded path (a Cyrillic slug is `%D1%80…`) on `config('app.url')`, then XML-escaped. No `changefreq`, no `priority` (search engines ignore them).
 */
final class Sitemap
{
    public const TTL_SECONDS = 3600;

    public const CHUNK = 5000;

    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const NS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';

    public function __construct(
        private readonly CatalogReader $reader,
        private readonly SubjectIndex $subjects,
    ) {
    }

    public static function chunkSize(): int
    {
        return max(1, (int) config('storefront.sitemap.chunk', self::CHUNK));
    }

    public function index(): string
    {
        return Cache::remember('storefront.sitemap.index.'.self::chunkSize(), self::TTL_SECONDS, function (): string {
            $entries = [
                ['loc' => $this->absolute('/sitemap/pages.xml'), 'lastmod' => $this->date(PageModel::query()->where('status', PageModel::PUBLISHED)->where('noindex', false)->max('updated_at'))],
                ['loc' => $this->absolute('/sitemap/categories.xml'), 'lastmod' => $this->date(DB::table('catalog_categories')->max('updated_at'))],
            ];

            $chunks = (int) ceil($this->reader->sitemapProductCount() / self::chunkSize());

            // SEO-1.1: a file of a subject kind is listed only when it has at least one row (the same rows as its index page); a kind switched off has none
            foreach ([['brands', CatalogSubjectKind::BRAND], ['seasons', CatalogSubjectKind::SEASON], ['collections', CatalogSubjectKind::COLLECTION], ['tags', CatalogSubjectKind::TAG]] as [$file, $kind]) {
                $rows = $this->subjects->rows($kind);

                if ($rows !== []) {
                    $entries[] = ['loc' => $this->absolute('/sitemap/'.$file.'.xml'), 'lastmod' => $this->date(self::latest($rows))];
                }
            }

            for ($n = 1; $n <= $chunks; $n++) {
                $entries[] = ['loc' => $this->absolute('/sitemap/products-'.$n.'.xml'), 'lastmod' => null];
            }

            $xml = '';

            foreach ($entries as $entry) {
                $xml .= '  <sitemap><loc>'.$this->x($entry['loc']).'</loc>'.($entry['lastmod'] === null ? '' : '<lastmod>'.$entry['lastmod'].'</lastmod>').'</sitemap>'."\n";
            }

            return $this->document('sitemapindex', $xml);
        });
    }

    public function pages(): string
    {
        return Cache::remember('storefront.sitemap.pages', self::TTL_SECONDS, function (): string {
            $xml = $this->url($this->absolute('/'), null);

            $pages = PageModel::query()->where('status', PageModel::PUBLISHED)->where('noindex', false)->orderBy('id')->get(['id', 'slug', 'updated_at']);

            foreach ($pages as $page) {
                $xml .= $this->url($this->absolute(PageReader::urlFor($page->slug)), $this->date($page->updated_at));
            }

            // SEO-1.1: the index pages, with the same rule as the pages themselves (no row = 404 = not listed; collections only while their switch is on)
            foreach ([['/brand', CatalogSubjectKind::BRAND], ['/collection', CatalogSubjectKind::COLLECTION]] as [$path, $kind]) {
                if ($this->subjects->rows($kind) !== []) {
                    $xml .= $this->url($this->absolute($path), null);
                }
            }

            return $this->document('urlset', $xml);
        });
    }

    /**
     * SEO-1.1: the file of one subject kind (`/sitemap/brands.xml`, `seasons`, `collections`, `tags`): one URL per row of its index, the subject's own
     * path (percent-encoded slug) and its `updated_at`. Null when there is no row (the controller answers 404; a null is not kept in the cache).
     */
    public function subject(CatalogSubjectKind $kind): ?string
    {
        $xml = Cache::remember('storefront.sitemap.'.$kind->value.'s', self::TTL_SECONDS, function () use ($kind): ?string {
            $rows = $this->subjects->rows($kind);

            if ($rows === []) {
                return null;
            }

            $body = '';

            foreach ($rows as $row) {
                $body .= $this->url($this->absolute($this->subjects->path($kind, $row->slug)), $this->date($row->updated_at));
            }

            return $this->document('urlset', $body);
        });

        return $xml === null ? null : (string) $xml;
    }

    public function categories(): string
    {
        return Cache::remember('storefront.sitemap.categories', self::TTL_SECONDS, function (): string {
            $nodes = [];
            $walk = function (array $level) use (&$nodes, &$walk): void {
                foreach ($level as $node) {
                    /** @var CategoryNode $node */
                    $nodes[] = $node;
                    $walk($node->children);
                }
            };
            $walk($this->reader->categoryTree());

            $dates = $nodes === [] ? [] : DB::table('catalog_categories')->whereIn('id', array_map(static fn (CategoryNode $node): int => (int) $node->id, $nodes))->pluck('updated_at', 'id')->all();
            $xml = '';

            foreach ($nodes as $node) {
                $xml .= $this->url($this->absolute($node->url), $this->date($dates[(int) $node->id] ?? null));
            }

            return $this->document('urlset', $xml);
        });
    }

    /** The XML of chunk `$n` (1-based), or null when there is no such chunk (the controller answers 404; a miss is never cached). */
    public function products(int $n): ?string
    {
        $size = self::chunkSize();
        $key = 'storefront.sitemap.products.'.$size.'.'.$n;
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $rows = $n < 1 ? [] : $this->reader->sitemapProducts(($n - 1) * $size, $size);

        if ($rows === []) {
            return null;
        }

        $xml = '';

        foreach ($rows as $row) {
            $image = $row['image'] === null ? '' : '<image:image><image:loc>'.$this->x($row['image']).'</image:loc></image:image>';
            $xml .= $this->url($this->absolute($row['url']), $this->date($row['updated_at']), $image);
        }

        $document = $this->document('urlset', $xml, image: true);
        Cache::put($key, $document, self::TTL_SECONDS);

        return $document;
    }

    /** The newest `updated_at` of some rows (null when none has one). @param  list<object>  $rows */
    private static function latest(array $rows): mixed
    {
        $newest = null;

        foreach ($rows as $row) {
            if ($row->updated_at !== null && ($newest === null || (string) $row->updated_at > (string) $newest)) {
                $newest = $row->updated_at;
            }
        }

        return $newest;
    }

    private function url(string $loc, ?string $lastmod, string $extra = ''): string
    {
        return '  <url><loc>'.$this->x($loc).'</loc>'.($lastmod === null ? '' : '<lastmod>'.$lastmod.'</lastmod>').$extra.'</url>'."\n";
    }

    private function document(string $root, string $body, bool $image = false): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<'.$root.' xmlns="'.self::NS.'"'.($image ? ' xmlns:image="'.self::NS_IMAGE.'"' : '').'>'."\n".$body.'</'.$root.'>'."\n";
    }

    private function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof CarbonInterface ? $value : Carbon::parse($value))->toAtomString();
    }

    private function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
