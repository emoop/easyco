<?php

namespace App\Storefront\Header;

use App\Content\Menus\MenuModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Design\LogoImage;
use InvalidArgumentException;

/**
 * PUBLISH (storefront-customization-design.md 4.4, D2): validates the layout, stores it as `header.layout.published` and as `header.compiled`
 * ({hash, layout}, the validated and normalised layout plus its sha1-12), recompiles the appearance stylesheet so the header's row and logo heights reach
 * the page as CSS variables (never inline styles), and REPLACES the appearance reader's cache entry. The rendered header HTML is NOT cached here: the
 * category menu in it changes with the catalog; the compiled layout itself is part of the cached appearance payload (zero queries warm).
 */
final class HeaderCompiler
{
    /**
     * @param  array<string, mixed>  $layout
     * @return array{hash: string, layout: array<string, mixed>}
     *
     * @throws InvalidArgumentException when the layout does not validate
     */
    public static function compiled(array $layout): array
    {
        $validation = HeaderValidator::validate($layout);

        if (! $validation->passes()) {
            throw new InvalidArgumentException('The header layout is not valid.');
        }

        $layout = self::withMenuKeys($validation->layout);

        return ['hash' => substr(sha1((string) json_encode($layout)), 0, 12), 'layout' => $layout];
    }

    /**
     * DS-2a.1: the layout with `menu_keys` (menu id => menu key) for every menu a `menu` element names explicitly, so the storefront finds that menu among the compiled menus (which are keyed by
     * menu key) WITHOUT a query. One query here, at publish and in the preview; a layout that names no menu gets none and is returned as it is.
     *
     * @param  array<string, mixed>  $layout  a VALIDATED layout
     * @return array<string, mixed>
     */
    public static function withMenuKeys(array $layout): array
    {
        unset($layout['menu_keys'], $layout['logo_images']);
        $ids = [];

        foreach (['desktop', 'mobile'] as $name) {
            $elements = $layout[$name]['drawer'] ?? [];

            foreach ($layout[$name]['rows'] as $row) {
                foreach (HeaderLayout::SLOTS as $slot) {
                    $elements = [...$elements, ...$row[$slot]];
                }
            }

            foreach ($elements as $element) {
                if ($element['type'] === 'menu' && is_int($element['settings']['menu'] ?? null)) {
                    $ids[] = $element['settings']['menu'];
                }
            }
        }

        $layout = self::withLogoImages($layout);

        if ($ids === []) {
            return $layout;
        }

        $keys = [];

        foreach (MenuModel::query()->whereIn('id', array_unique($ids))->orderBy('id')->pluck('key', 'id')->all() as $id => $key) {
            $keys[(string) $id] = (string) $key;
        }

        return $keys === [] ? $layout : $layout + ['menu_keys' => $keys];
    }

    /**
     * LG-1: the rendition (url, width, height) of every logo picture a `logo` element names, so the storefront prints it WITHOUT a query (the views never query). The smallest WebP variant at least twice as wide
     * as the logo is shown (the widest of its uses: the desktop and the phone logo may have different heights), else the largest. A picture that is missing, not ready or not a PNG / WebP gets NO entry:
     * the logo then silently falls back to the static file. One query per picture, at publish and in the preview.
     *
     * @param  array<string, mixed>  $layout  a VALIDATED layout
     * @return array<string, mixed>
     */
    public static function withLogoImages(array $layout): array
    {
        unset($layout['logo_images']);
        $heights = [];

        foreach (['desktop', 'mobile'] as $name) {
            foreach ($layout[$name]['rows'] as $row) {
                foreach (HeaderLayout::SLOTS as $slot) {
                    foreach ($row[$slot] as $element) {
                        $image = $element['type'] === 'logo' ? ($element['settings']['image'] ?? null) : null;

                        if (is_int($image)) {
                            $heights[$image] = max($heights[$image] ?? 0, (int) ($element['settings']['height'] ?? 0));
                        }
                    }
                }
            }
        }

        $images = [];

        foreach ($heights as $id => $height) {
            $rendition = LogoImage::rendition($id, $height);

            $rendition !== null && $images[(string) $id] = $rendition;
        }

        return $images === [] ? $layout : $layout + ['logo_images' => $images];
    }

    /**
     * Publishes a layout (already validated by the caller or not: it is validated here again).
     *
     * @param  array<string, mixed>  $layout
     * @return array{hash: string, layout: array<string, mixed>}
     */
    public function publish(array $layout, SiteSettingsRepository $settings): array
    {
        $compiled = self::compiled($layout);
        $json = (string) json_encode($compiled, JSON_THROW_ON_ERROR);

        $settings->set(AppearanceSettings::HEADER_PUBLISHED_KEY, (string) json_encode($compiled['layout'], JSON_THROW_ON_ERROR));
        $settings->set(AppearanceSettings::HEADER_COMPILED_KEY, $json);

        app(AppearanceRecompiler::class)->run($settings);

        return $compiled;
    }

    /** Forgets the published layout: the seeded default is shown again. */
    public function unpublish(SiteSettingsRepository $settings): void
    {
        $settings->forget(AppearanceSettings::HEADER_PUBLISHED_KEY);
        $settings->forget(AppearanceSettings::HEADER_COMPILED_KEY);

        app(AppearanceRecompiler::class)->run($settings);
    }
}
