<?php

namespace App\Storefront\Sections;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * P4b: what the «Секции на продукта» zone stores, in one place (the pattern of BelowStore). A PUBLISHED set (`design.sections.published`: the validated JSON of SectionSet, read by the admin and by the product
 * form's «Информация» tab; the storefront reads the compiled copy, SectionCompiler) and, beside it, a DRAFT. «Приложи» writes the draft only; «Публикувай» writes the set and its compiled copy and forgets the
 * draft; «Върни публикуваното» forgets the draft. A draft equal to what is published is never kept.
 *
 * @phpstan-import-type Set from SectionSet
 */
final class SectionStore
{
    /** The published set (cleaned on read). @return Set */
    public static function values(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::SECTIONS_PUBLISHED_KEY);

        return SectionSet::read(is_string($json) && $json !== '' ? json_decode($json, true) : null);
    }

    /** The working copy the screen starts from: the draft when there is one, else the published set. @return Set */
    public static function startingValues(): array
    {
        return self::draft() ?? self::values();
    }

    /** The stored draft as a clean working copy, or null when there is none. @return Set|null */
    public static function draft(): ?array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::SECTIONS_DRAFT_KEY);
        $stored = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($stored) ? SectionSet::read($stored) : null;
    }

    public static function hasUnpublishedChanges(): bool
    {
        $draft = self::draft();

        return $draft !== null && $draft != self::values();
    }

    /** «Приложи». @param  Set  $clean  VALIDATED */
    public static function saveDraft(array $clean): void
    {
        $settings = app(SiteSettingsRepository::class);

        $clean == self::values() ? $settings->forget(AppearanceSettings::SECTIONS_DRAFT_KEY) : $settings->set(AppearanceSettings::SECTIONS_DRAFT_KEY, (string) json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        self::log('draft');
    }

    public static function discard(): void
    {
        app(SiteSettingsRepository::class)->forget(AppearanceSettings::SECTIONS_DRAFT_KEY);

        self::log('discarded');
    }

    /**
     * «Публикувай». @param  Set  $clean  VALIDATED
     *
     * @throws \InvalidArgumentException when the compiled copy would be too large (nothing is changed)
     */
    public static function publish(array $clean): void
    {
        $settings = app(SiteSettingsRepository::class);
        app(SectionCompiler::class)->publish($clean, $settings);
        $settings->forget(AppearanceSettings::SECTIONS_DRAFT_KEY);
        app(\App\Storefront\Appearance\StorefrontAppearance::class)->refresh();

        self::log('published');
    }

    private static function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_sections', 'sections', $action, null, 'saved');
    }
}
