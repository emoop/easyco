<?php

namespace App\Storefront\PageDesign;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\GallerySettings;
use App\Storefront\Header\AppearanceRecompiler;

/**
 * GL-2 / DS-5: what the two plain sections of the «Дизайн» screen store, in one place. A section has a PUBLISHED value (what the shop uses) and, since DS-5, a DRAFT beside it (the working copy):
 * «Приложи» writes the draft only; «Публикувай» turns the draft into the published value and forgets it; «Върни публикуваното» forgets it. A draft equal to the published value is never kept, so
 * `draft() === null` means «nothing to publish». The storefront reads ONLY the published storage.
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

    /** The values the screen starts from: the draft when there is one, else the published ones. @return array<string, mixed> */
    public static function startingValues(string $section): array
    {
        return self::draft($section) ?? self::values($section);
    }

    /** The stored draft as validated values, or null when there is none (or it does not validate). @return array<string, mixed>|null */
    public static function draft(string $section): ?array
    {
        $stored = PageDesign::decode(app(SiteSettingsRepository::class)->get(self::draftKey($section)));

        if ($stored === null) {
            return null;
        }

        [$clean, $errors] = PageDesign::validate($section, $stored);

        return $errors === [] ? $clean : null;
    }

    /** Is there a draft that differs from what is published? */
    public static function hasUnpublishedChanges(string $section): bool
    {
        $draft = self::draft($section);

        return $draft !== null && $draft != self::values($section);
    }

    /** «Приложи»: the working copy is stored. Values equal to the published ones are not a draft: the key is forgotten. @param  array<string, mixed>  $clean  VALIDATED */
    public static function saveDraft(string $section, array $clean): void
    {
        $settings = app(SiteSettingsRepository::class);
        $key = self::draftKey($section);

        $clean == self::values($section) ? $settings->forget($key) : $settings->set($key, (string) json_encode($clean, JSON_THROW_ON_ERROR));

        app(ActivityLogger::class)->logFieldChanged('storefront_page_design', 'page_design', $section.'_draft', null, 'saved');
    }

    /** «Върни публикуваното»: the draft is forgotten. */
    public static function discard(string $section): void
    {
        app(SiteSettingsRepository::class)->forget(self::draftKey($section));

        app(ActivityLogger::class)->logFieldChanged('storefront_page_design', 'page_design', $section.'_discarded', null, 'saved');
    }

    /** «Публикувай»: the values become the published ones (stylesheet recompiled, the reader's cache entry replaced) and the draft is forgotten. @param  array<string, mixed>  $clean  VALIDATED */
    public static function publish(string $section, array $clean): void
    {
        self::save($section, $clean);
        app(SiteSettingsRepository::class)->forget(self::draftKey($section));
    }

    public static function draftKey(string $section): string
    {
        return $section === PageDesign::PAGE ? AppearanceSettings::PAGE_DESIGN_DRAFT_KEY : AppearanceSettings::PRODUCT_DESIGN_DRAFT_KEY;
    }

    /** @param  array<string, mixed>  $clean  the section's VALIDATED values */
    private static function save(string $section, array $clean): void
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
