<?php

namespace App\Storefront\Menu;

use App\Rules\PlainText;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\Log;

/**
 * Re-validates the compiled menus every time the cached payload is read (D3): shape, types, lengths, the link rule. A menu that does not pass renders
 * as an EMPTY menu and is logged once per process, so a hand-edited database row cannot print a `javascript:` link, an unknown element or a huge tree.
 * The result is rebuilt from known keys only: nothing unvalidated is passed on to a view.
 */
final class CompiledMenus
{
    private const MAX_DEPTH = 5;

    private const MAX_NODES = 600;

    /** @var array<string, true> */
    private static array $logged = [];

    private int $nodes = 0;

    /**
     * @param  mixed  $menus  the decoded `menus` object of the stored value
     * @return array<string, list<array<string, mixed>>> menu key => items (an invalid menu is [])
     */
    public static function read(mixed $menus): array
    {
        if (! is_array($menus)) {
            return [];
        }

        $out = [];

        foreach ($menus as $key => $items) {
            if (! is_string($key) || preg_match('/^[a-z0-9-]{1,40}$/', $key) !== 1) {
                continue;
            }

            $reader = new self();
            $clean = is_array($items) && array_is_list($items) ? $reader->list($items, 0) : null;

            if ($clean === null) {
                self::warn($key);
                $clean = [];
            }

            $out[$key] = $clean;
        }

        return $out;
    }

    /** Forgets what was logged (tests only). */
    public static function forgetWarnings(): void
    {
        self::$logged = [];
    }

    private static function warn(string $key): void
    {
        if (isset(self::$logged[$key])) {
            return;
        }

        self::$logged[$key] = true;
        Log::warning('storefront.menu: the compiled menu "'.$key.'" is not valid and renders empty');
    }

    /**
     * @param  array<mixed>  $items
     * @return list<array<string, mixed>>|null
     */
    private function list(array $items, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        $out = [];

        foreach ($items as $item) {
            if (++$this->nodes > self::MAX_NODES) {
                return null;
            }

            $node = is_array($item) ? $this->node($item, $depth) : null;

            if ($node === null) {
                return null;
            }

            $out[] = $node;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function node(array $item, int $depth): ?array
    {
        return match ($item['type'] ?? null) {
            'link' => $this->link($item, $depth),
            'column' => $this->column($item, $depth),
            'image' => $this->image($item),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function link(array $item, int $depth): ?array
    {
        $seeAll = ($item['see_all'] ?? false) === true;
        $label = $item['label'] ?? null;

        if (! ($seeAll && $label === null) && ! $this->text($label, 1, 120)) {
            return null;
        }

        if (! $this->url($item['url'] ?? null) || ! is_bool($item['new_tab'] ?? null) || ! in_array($item['submenu'] ?? null, ['none', 'dropdown', 'mega'], true)) {
            return null;
        }

        $children = $this->list(is_array($item['children'] ?? null) ? $item['children'] : [], $depth + 1);

        if ($children === null) {
            return null;
        }

        $node = ['type' => 'link', 'label' => $label, 'url' => $item['url'], 'new_tab' => $item['new_tab'], 'submenu' => $item['submenu'], 'children' => $children];

        return $seeAll ? $node + ['see_all' => true] : $node;
    }

    /** @return array<string, mixed>|null */
    private function column(array $item, int $depth): ?array
    {
        $label = $item['label'] ?? null;

        if ($label !== null && ! $this->text($label, 1, 120)) {
            return null;
        }

        $children = $this->list(is_array($item['children'] ?? null) ? $item['children'] : [], $depth + 1);

        return $children === null ? null : ['type' => 'column', 'label' => $label, 'children' => $children];
    }

    /** @return array<string, mixed>|null */
    private function image(array $item): ?array
    {
        $label = $item['label'] ?? null;
        $link = $item['url'] ?? null;
        $srcset = [];

        foreach (is_array($item['srcset'] ?? null) ? $item['srcset'] : [] as $row) {
            if (! is_array($row) || ! $this->imageUrl($row['url'] ?? null) || ! is_int($row['width'] ?? null) || $row['width'] < 1 || count($srcset) >= 8) {
                return null;
            }

            $srcset[] = ['url' => $row['url'], 'width' => $row['width']];
        }

        if (($label !== null && ! $this->text($label, 1, 80)) || ($link !== null && ! $this->url($link))
            || ! $this->text($item['alt'] ?? null, 1, 200) || ! $this->imageUrl($item['src'] ?? null)
            || ! is_int($item['width'] ?? null) || $item['width'] < 1 || ! is_int($item['height'] ?? null) || $item['height'] < 1
            || ! is_bool($item['new_tab'] ?? null)
        ) {
            return null;
        }

        return ['type' => 'image', 'label' => $label, 'url' => $link, 'new_tab' => $item['new_tab'], 'alt' => $item['alt'], 'src' => $item['src'], 'srcset' => $srcset, 'width' => $item['width'], 'height' => $item['height']];
    }

    private function text(mixed $value, int $min, int $max): bool
    {
        if (! is_string($value) || mb_strlen($value) < $min || mb_strlen($value) > $max) {
            return false;
        }

        $bad = false;
        (new PlainText)->validate('text', $value, static function () use (&$bad): void {
            $bad = true;
        });

        return ! $bad;
    }

    private function url(mixed $value): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= 500 && AppearanceSettings::isSafeBannerUrl($value);
    }

    /** A picture address: root-relative or http(s); never a scheme that runs code. */
    private function imageUrl(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > 1000 || preg_match('/[\x00-\x20"<>\\\\]/', $value) === 1) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//');
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
