<?php

namespace App\Storefront\Menu;

use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Content\Menus\MenuValidator;
use App\Rules\PlainText;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\Log;

/**
 * Re-validates the compiled menus every time the cached payload is read (D3, MENU-2): shape, types, lengths, the link rule, the badge, the HTML block (cleaned again). A menu that does not pass renders
 * as an EMPTY menu and is logged once per process, so a hand-edited database row cannot print a `javascript:` link, an unknown element or a huge tree.
 * The result is rebuilt from known keys only: nothing unvalidated is passed on to a view.
 */
final class CompiledMenus
{
    private const MAX_DEPTH = 5;

    private const MAX_NODES = 600;

    private const MAX_HTML = 200_000;

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

    /**
     * The menu key of each location, from the stored value: a location whose menu is not in the compiled menus renders nothing.
     *
     * @param  mixed  $locations  the decoded `locations` object of the stored value
     * @param  array<string, mixed>  $menus  the validated menus by key
     * @return array<string, ?string>
     */
    public static function locations(mixed $locations, array $menus): array
    {
        $out = [];

        foreach (['header', 'drawer', 'footer'] as $location) {
            $key = is_array($locations) ? ($locations[$location] ?? null) : null;
            $out[$location] = is_string($key) && isset($menus[$key]) ? $key : null;
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
            'heading' => $this->heading($item, $depth),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function link(array $item, int $depth): ?array
    {
        if (! $this->text($item['label'] ?? null, 1, 120)) {
            return null;
        }

        if (! $this->url($item['url'] ?? null) || ! is_bool($item['new_tab'] ?? null) || ! in_array($item['submenu'] ?? null, ['none', 'dropdown', 'mega'], true)) {
            return null;
        }

        $badge = $this->badge($item['badge'] ?? null);
        $html = $item['html'] ?? null;

        if ($badge === false || ($html !== null && (! is_string($html) || strlen($html) > self::MAX_HTML))) {
            return null;
        }

        $children = $this->list(is_array($item['children'] ?? null) ? $item['children'] : [], $depth + 1);

        if ($children === null) {
            return null;
        }

        // The block is cleaned AGAIN on every read: a hand-edited row or cache entry cannot print a script, a style or an event handler.
        $html = $html === null || $item['submenu'] !== 'mega' ? null : HtmlBlockSanitizer::render($html);

        return ['type' => 'link', 'label' => $item['label'], 'url' => $item['url'], 'new_tab' => $item['new_tab'], 'submenu' => $item['submenu'], 'badge' => $badge, 'html' => $html === '' ? null : $html, 'children' => $children];
    }

    /** @return array<string, mixed>|null */
    private function heading(array $item, int $depth): ?array
    {
        if (! $this->text($item['label'] ?? null, 1, 120)) {
            return null;
        }

        $children = $this->list(is_array($item['children'] ?? null) ? $item['children'] : [], $depth + 1);

        return $children === null ? null : ['type' => 'heading', 'label' => $item['label'], 'children' => $children];
    }

    /** @return array{text: string, color: ?string}|null|false false = not valid */
    private function badge(mixed $badge): array|null|false
    {
        if ($badge === null) {
            return null;
        }

        if (! is_array($badge) || ! $this->text($badge['text'] ?? null, 1, 16)) {
            return false;
        }

        $color = $badge['color'] ?? null;

        if ($color !== null && ! MenuValidator::isBadgeColor($color)) {
            return false;
        }

        return ['text' => $badge['text'], 'color' => is_string($color) ? strtolower($color) : null];
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
}
