<?php

namespace App\Storefront\Header;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
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

        return ['hash' => substr(sha1((string) json_encode($validation->layout)), 0, 12), 'layout' => $validation->layout];
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
