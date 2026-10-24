<?php

namespace App\Media\Usage;

use App\Filament\Resources\HtmlBlockResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The pictures put INTO the text of an HTML block by the editor's picture button (ML-2): every `<img src>` that points under the storage address `/storage/uploads/…` is mapped to its asset by the
 * path rule (media-library-design.md §1): a variant `uploads/Y/m/{uuid}-{tier}.webp` or the original `uploads/Y/m/{uuid}.{ext}` both belong to the asset whose `path` is `uploads/Y/m/{uuid}.…`.
 * The blocks come from the same single read as HtmlBlockPictures (HtmlBlockRows); the asset ids come from one query per 200 distinct files. Anything that is not under `/storage/uploads/` (an
 * address of another site, a loose old file in `html-blocks/`) is not ours and is ignored.
 */
final class HtmlBlockBody implements MediaUsageProvider
{
    /** @var list<array{block: int, title: string, id: int}>|null one entry per picture found in a text */
    private ?array $found = null;

    public function __construct(private readonly HtmlBlockRows $blocks = new HtmlBlockRows)
    {
    }

    public function counts(array $ids): array
    {
        $wanted = array_flip($ids);
        $counts = [];

        foreach ($this->found() as $use) {
            isset($wanted[$use['id']]) && $counts[$use['id']] = ($counts[$use['id']] ?? 0) + 1;
        }

        return $counts;
    }

    public function usagesOf(int $id): array
    {
        $seen = [];
        $usages = [];

        foreach ($this->found() as $use) {
            if ($use['id'] === $id && ! isset($seen[$use['block']])) {
                $seen[$use['block']] = true;
                $usages[] = new MediaUsage('html_block_body', __('media.usage.html_block_body', ['name' => $use['title']]), HtmlBlockResource::getUrl('edit', ['record' => $use['block']]));
            }
        }

        return $usages;
    }

    public function excludeUsed(Builder $query): void
    {
        $used = array_values(array_unique(array_column($this->found(), 'id')));

        $used !== [] && $query->whereNotIn('catalog_media.id', $used);
    }

    /** @return list<array{block: int, title: string, id: int}> */
    private function found(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }

        // block id => list of base paths ("uploads/Y/m/{name-without-extension-or-tier}") its text uses
        $bases = [];

        foreach ($this->blocks->all() as $block) {
            foreach (self::bases((string) $block->body) as $base) {
                $bases[(int) $block->id][$base] = true;
            }
        }

        $every = array_values(array_unique(array_merge([], ...array_map('array_keys', $bases))));
        $assets = [];

        foreach (array_chunk($every, 200) as $chunk) {
            $rows = DB::table('catalog_media')->where(function ($q) use ($chunk): void {
                foreach ($chunk as $base) {
                    $q->orWhere('path', 'like', self::escape($base).'.%');
                }
            })->get(['id', 'path']);

            foreach ($rows as $row) {
                $assets[self::base((string) $row->path)][] = (int) $row->id;
            }
        }

        $titles = $this->blocks->all()->pluck('title', 'id');
        $found = [];

        foreach ($bases as $blockId => $list) {
            foreach (array_keys($list) as $base) {
                foreach ($assets[$base] ?? [] as $assetId) {
                    $found[] = ['block' => (int) $blockId, 'title' => (string) ($titles[$blockId] ?? ''), 'id' => $assetId];
                }
            }
        }

        return $this->found = $found;
    }

    /** @return list<string> the base paths of the `<img src>` that point under /storage/uploads/ */
    private static function bases(string $html): array
    {
        if ($html === '' || ! str_contains($html, '/uploads/')) {
            return [];
        }

        preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches);
        $bases = [];

        foreach (array_merge($matches[1], $matches[2]) as $src) {
            $address = html_entity_decode((string) $src);
            $host = parse_url($address, PHP_URL_HOST);
            $path = parse_url($address, PHP_URL_PATH);

            // an address of ANOTHER site is not our storage, whatever its path looks like
            if (is_string($host) && ! in_array(strtolower($host), self::ownHosts(), true)) {
                continue;
            }

            // only our own storage: `/storage/uploads/Y/m/{file}`
            if (is_string($path) && preg_match('#/storage/(uploads/\d{4}/\d{2}/[A-Za-z0-9._-]+)$#', $path, $m) === 1) {
                $bases[self::base($m[1])] = true;
            }
        }

        return array_keys($bases);
    }

    /** @return list<string> the host names this shop serves its storage from (the application address and the disk's own address) */
    private static function ownHosts(): array
    {
        $hosts = [];

        foreach ([(string) config('app.url'), (string) Storage::disk((string) config('services.media.default_disk', 'public'))->url('x')] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            is_string($host) && $hosts[] = strtolower($host);
        }

        return array_values(array_unique($hosts));
    }

    /** `uploads/2026/10/abc-large.webp` and `uploads/2026/10/abc.jpg` both give `uploads/2026/10/abc` */
    private static function base(string $path): string
    {
        $path = (string) preg_replace('/-(?:thumbnail|medium|large|admin_grid)\.webp$/', '', $path);
        $dot = strrpos($path, '.');
        $slash = strrpos($path, '/');

        return $dot !== false && ($slash === false || $dot > $slash) ? substr($path, 0, $dot) : $path;
    }

    private static function escape(string $text): string
    {
        return addcslashes($text, '%_'.chr(92));
    }
}
