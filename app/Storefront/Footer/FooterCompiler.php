<?php

namespace App\Storefront\Footer;

use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Content\Menus\MenuModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Header\AppearanceRecompiler;
use App\Storefront\Menu\CompiledMenus;
use App\Storefront\Menu\MenuRecompiler;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PUBLISH of the footer (FT-1): validates the layout, stores it as `footer.layout.published` and as `footer.compiled`
 * ({hash, layout, menus, blocks}: the validated layout PLUS what its blocks need so that printing costs no query: the TOP-LEVEL links of every menu a `menu` block shows, and
 * the sanitized HTML of every HTML block), recompiles the token stylesheet (the footer's colours and border reach the page as CSS variables, never inline styles) and replaces
 * the appearance reader's cache entry. `refresh()` rebuilds the compiled copy from the PUBLISHED layout when something it shows changed (a menu, an item, a page, a category,
 * an HTML block: MenuCompiler calls it after every menu rebuild), so a deleted menu or block simply disappears from the footer.
 */
final class FooterCompiler
{
    public const MAX_BYTES = 262_144;

    /** The key the menu of the footer LOCATION has among the compiled menus (a `menu` block with no menu chosen). */
    public const LOCATION_KEY = 'footer';

    /**
     * What a layout needs from the rest of the shop, as it is NOW: the top-level links of its menus and the sanitized HTML of its blocks.
     *
     * @param  array<string, mixed>  $layout  a VALIDATED layout
     * @param  array<string, list<array<string, mixed>>>|null  $menus  the freshly compiled menus by key (null = the stored ones)
     * @param  array<string, ?string>|null  $locations  the menu key of each location (null = the stored ones)
     * @return array{hash: string, layout: array<string, mixed>, menus: array<string, list<array<string, mixed>>>, blocks: array<string, string>}
     */
    public static function compiled(array $layout, ?array $menus = null, ?array $locations = null): array
    {
        $menuIds = [];
        $blockIds = [];

        foreach ($layout['upper']['rows'] as $row) {
            foreach ($row['columns'] as $column) {
                foreach ($column['blocks'] as $block) {
                    if ($block['type'] === 'menu') {
                        $menuIds[(string) ($block['settings']['menu'] ?? '')] = $block['settings']['menu'];
                    } elseif ($block['type'] === 'html_block' && is_int($block['settings']['html_block'] ?? null)) {
                        $blockIds[] = $block['settings']['html_block'];
                    }
                }
            }
        }

        if ($menus === null || $locations === null) {
            $stored = self::storedMenus();
            $menus ??= $stored['menus'];
            $locations ??= $stored['locations'];
        }

        $out = [];

        if ($menuIds !== []) {
            $keys = MenuModel::query()->whereIn('id', array_filter(array_values($menuIds)))->pluck('key', 'id')->all();

            foreach ($menuIds as $id) {
                $key = $id === null ? ($locations['footer'] ?? null) : ($keys[$id] ?? null);

                if (is_string($key) && isset($menus[$key])) {
                    $out[self::menuKey($id)] = self::topLevel($menus[$key]);
                }
            }
        }

        $blocks = [];

        if ($blockIds !== []) {
            foreach (DB::table('html_blocks')->whereIn('id', array_unique($blockIds))->pluck('body', 'id') as $id => $body) {
                $html = HtmlBlockSanitizer::render((string) $body);

                if ($html !== '') {
                    $blocks[(string) $id] = $html;
                }
            }
        }

        return ['hash' => substr(sha1((string) json_encode($layout)), 0, 12), 'layout' => $layout, 'menus' => $out, 'blocks' => $blocks];
    }

    /** The key a `menu` block's menu has in the compiled copy: `m12`, or `footer` for "the menu of the footer location". */
    public static function menuKey(?int $id): string
    {
        return $id === null ? self::LOCATION_KEY : 'm'.$id;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>> only the first level: no dropdown, no mega, no columns
     */
    private static function topLevel(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'link') {
                continue;
            }

            $out[] = ['type' => 'link', 'label' => $item['label'], 'url' => $item['url'], 'new_tab' => $item['new_tab'], 'submenu' => 'none', 'badge' => $item['badge'], 'html' => null, 'children' => []];
        }

        return $out;
    }

    /** @return array{menus: array<string, list<array<string, mixed>>>, locations: array<string, ?string>} */
    private static function storedMenus(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::MENUS_COMPILED_KEY);
        $data = is_string($json) ? json_decode($json, true) : null;
        $menus = CompiledMenus::read(is_array($data) ? ($data['menus'] ?? null) : null);

        return ['menus' => $menus, 'locations' => CompiledMenus::locations(is_array($data) ? ($data['locations'] ?? null) : null, $menus)];
    }

    /**
     * Publishes a layout (validated again here).
     *
     * @param  array<string, mixed>  $layout
     * @return array{hash: string, layout: array<string, mixed>, menus: array<string, mixed>, blocks: array<string, string>}
     *
     * @throws InvalidArgumentException when the layout does not validate or the compiled copy is too large
     */
    public function publish(array $layout, SiteSettingsRepository $settings): array
    {
        $validation = FooterValidator::validate($layout);

        if (! $validation->passes()) {
            throw new InvalidArgumentException('The footer layout is not valid.');
        }

        $compiled = self::compiled($validation->layout);
        $json = (string) json_encode($compiled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The compiled footer is too large.');
        }

        $settings->set(AppearanceSettings::FOOTER_PUBLISHED_KEY, (string) json_encode($validation->layout, JSON_THROW_ON_ERROR));
        $settings->set(AppearanceSettings::FOOTER_COMPILED_KEY, $json);

        app(AppearanceRecompiler::class)->run($settings);
        app(MenuRecompiler::class)->forgetHolds();

        return $compiled;
    }

    /** Forgets the published layout: the shop shows its old footer again. */
    public function unpublish(SiteSettingsRepository $settings): void
    {
        $settings->forget(AppearanceSettings::FOOTER_PUBLISHED_KEY);
        $settings->forget(AppearanceSettings::FOOTER_COMPILED_KEY);

        app(AppearanceRecompiler::class)->run($settings);
        app(MenuRecompiler::class)->forgetHolds();
    }

    /**
     * Rebuilds the compiled copy from the PUBLISHED layout (nothing happens when none is published or it no longer validates); stores it and replaces the reader's cache
     * entry only when it changed. Returns whether it changed.
     *
     * @param  array<string, list<array<string, mixed>>>|null  $menus
     * @param  array<string, ?string>|null  $locations
     */
    public function refresh(?array $menus = null, ?array $locations = null): bool
    {
        $settings = app(SiteSettingsRepository::class);
        $json = $settings->get(AppearanceSettings::FOOTER_PUBLISHED_KEY);
        $layout = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($layout)) {
            return false;
        }

        $validation = FooterValidator::validate($layout);

        if (! $validation->passes()) {
            return false;
        }

        $new = (string) json_encode(self::compiled($validation->layout, $menus, $locations), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($new) > self::MAX_BYTES || $new === $settings->get(AppearanceSettings::FOOTER_COMPILED_KEY)) {
            return false;
        }

        $settings->set(AppearanceSettings::FOOTER_COMPILED_KEY, $new);
        app(StorefrontAppearance::class)->refresh();

        return true;
    }
}
