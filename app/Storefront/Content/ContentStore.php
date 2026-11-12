<?php

namespace App\Storefront\Content;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * P3: what the «Съдържание» zone stores, in one place (the pattern of SidebarStore). A PUBLISHED set (`design.content.published`: the validated JSON of ContentSet, read by the admin; the storefront reads the
 * compiled copy, ContentCompiler) and, beside it, a DRAFT (the working copy). «Приложи» writes the draft only; «Публикувай» writes the set and its compiled copy and forgets the draft; «Върни публикуваното» forgets
 * the draft. A draft equal to what is published is never kept.
 *
 * @phpstan-import-type Set from ContentSet
 */
final class ContentStore
{
    /** The subjects that exist now, by kind (one small read each; the admin only). @return array<string, list<int>> */
    public static function existing(): array
    {
        return [
            'categories' => self::ids('catalog_categories'),
            'brands' => self::ids('catalog_brands'),
            'seasons' => self::ids('catalog_seasons'),
            'tags' => self::ids('catalog_tags'),
            // P6e-2: the collections' own templates stay valid whatever the switch says (they come back when it is switched on)
            'collections' => self::ids('catalog_collections'),
        ];
    }

    /** The published set (cleaned: a ghost subject is dropped). @return Set */
    public static function values(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY);

        return ContentSet::read(is_string($json) && $json !== '' ? json_decode($json, true) : null, self::existing());
    }

    /** The working copy the screen starts from: the draft when there is one, else the published set. @return Set */
    public static function startingValues(): array
    {
        return self::draft() ?? self::values();
    }

    /** The stored draft as a clean working copy, or null when there is none. @return Set|null */
    public static function draft(): ?array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_DRAFT_KEY);
        $stored = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($stored) ? ContentSet::read($stored, self::existing()) : null;
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

        $clean == self::values() ? $settings->forget(AppearanceSettings::CONTENT_DRAFT_KEY) : $settings->set(AppearanceSettings::CONTENT_DRAFT_KEY, (string) json_encode($clean, JSON_THROW_ON_ERROR));

        self::log('draft');
    }

    public static function discard(): void
    {
        app(SiteSettingsRepository::class)->forget(AppearanceSettings::CONTENT_DRAFT_KEY);

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
        app(ContentCompiler::class)->publish($clean, $settings);
        $settings->forget(AppearanceSettings::CONTENT_DRAFT_KEY);
        app(\App\Storefront\Appearance\StorefrontAppearance::class)->refresh();

        self::log('published');
    }

    /** @return list<int> */
    private static function ids(string $table): array
    {
        return array_map('intval', DB::table($table)->orderBy('id')->pluck('id')->all());
    }

    private static function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_content', 'content', $action, null, 'saved');
    }
}
