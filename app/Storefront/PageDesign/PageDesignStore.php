<?php

namespace App\Storefront\PageDesign;

use App\Services\ActivityLogger;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\GallerySettings;
use App\Storefront\Header\AppearanceRecompiler;

/**
 * GL-2: what the two plain sections of the «Дизайн» screen store, in one place: the values the screen starts from and the save. A section has NO draft: «Запази» is live (like the top banner).
 *
 * The VARIABLE fields go into one JSON setting per section, written only when something differs from the defaults (the key is forgotten when everything is at its default, so a reset leaves
 * the settings table as it was before the section was ever touched). The five gallery choices are written to their own `storefront.gallery.*` keys through the settings repository, again only when
 * they differ from GallerySettings' defaults. After either, the token stylesheet is recompiled and the reader's cache entry REPLACED, exactly as the other Design sections do, so the next request
 * already shows the saved values. The values given to save() must already be VALIDATED (PageDesign::validate).
 */
final class PageDesignStore
{
    /**
     * The values a section starts from: the stored ones (validated again; a section that does not validate starts from its defaults) and, for the product section, the gallery choices GallerySettings
     * reads at render.
     *
     * @return array<string, mixed>
     */
    public static function values(string $section): array
    {
        $settings = app(SiteSettingsRepository::class);
        $values = PageDesign::defaults($section);
        $stored = PageDesign::decode($settings->get(PageDesign::KEYS[$section]));

        if ($stored !== null) {
            [$clean, $errors] = PageDesign::validate($section, $stored, false);

            $errors === [] && $values = array_replace($values, $clean);
        }

        if ($section === PageDesign::PRODUCT) {
            $gallery = GallerySettings::fromRepository($settings);
            $values = array_replace($values, [
                'gallery_desktop_mode' => $gallery->desktopMode,
                'gallery_mobile_mode' => $gallery->mobileMode,
                'gallery_width' => (string) $gallery->widthPercent,
                'gallery_odd_last' => $gallery->oddLast,
                'gallery_hover_zoom' => $gallery->hoverZoom,
            ]);
        }

        return $values;
    }

    /** @param  array<string, mixed>  $clean  the section's VALIDATED values */
    public static function save(string $section, array $clean): void
    {
        $settings = app(SiteSettingsRepository::class);
        $variables = array_intersect_key($clean, PageDesign::variableFields($section));
        $key = PageDesign::KEYS[$section];

        PageDesign::isDefault($section, $variables) ? $settings->forget($key) : $settings->set($key, (string) json_encode($variables, JSON_THROW_ON_ERROR));

        if ($section === PageDesign::PRODUCT) {
            foreach (PageDesign::GALLERY_KEYS as $field => $galleryKey) {
                $value = (string) ($clean[$field] ?? PageDesign::fields($section)[$field]['default']);

                $value === (string) PageDesign::fields($section)[$field]['default'] ? $settings->forget($galleryKey) : $settings->set($galleryKey, $value);
            }
        }

        app(AppearanceRecompiler::class)->run($settings);

        app(ActivityLogger::class)->logFieldChanged('storefront_page_design', 'page_design', $section, null, 'saved');
    }
}
