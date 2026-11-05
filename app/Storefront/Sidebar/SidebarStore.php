<?php

namespace App\Storefront\Sidebar;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;

/**
 * P1: what the «Сайдбар» zone stores, in one place (the pattern of PageDesignStore). A PUBLISHED list (`design.sidebar.filters`: `{"filters": [{key, show}]}`, read by the storefront with the rest of the appearance)
 * and, beside it, a DRAFT (the working copy: the same list plus the kinds of the three built-ins). «Приложи» writes the draft only; «Публикувай» writes the list, writes the three kinds into the published «Каталог»
 * JSON where they have always lived (`filterBrand` / `filterSeason` / `filterTag`) and forgets the draft; «Върни публикуваното» forgets it. A draft equal to what is published is never kept.
 *
 * A working copy is the VALIDATED shape of SidebarFilters::validate: `{filters: list<{key, show}>, kinds: {brand, season, tag}}`.
 */
final class SidebarStore
{
    /**
     * The published working copy: the stored list cleaned against the shop's known filters (or the defaults when none was ever saved) and the kinds of the built-ins as «Каталог» holds them (an older `off` is
     * shown as `list`; with no stored list it is what makes that built-in hidden).
     *
     * @return array{filters: list<array{key: string, show: bool}>, kinds: array<string, string>}
     */
    public static function values(): array
    {
        $known = app(FilterCatalog::class)->knownKeys();
        $stored = PageDesignStore::values(PageDesign::CATALOG);
        $raw = ['brand' => (string) $stored['filterBrand'], 'season' => (string) $stored['filterSeason'], 'tag' => (string) $stored['filterTag']];
        $rows = SidebarFilters::rowsFromJson(app(SiteSettingsRepository::class)->get(AppearanceSettings::SIDEBAR_FILTERS_KEY));

        return [
            'filters' => SidebarFilters::effective($rows, $known, $raw, app(FilterCatalog::class)->hiddenByDefault()),
            'kinds' => array_map(static fn (string $kind): string => SidebarFilters::kindToPrint($kind), $raw),
        ];
    }

    /** The working copy the screen starts from: the draft when there is one, else the published one. @return array{filters: list<array{key: string, show: bool}>, kinds: array<string, string>} */
    public static function startingValues(): array
    {
        return self::draft() ?? self::values();
    }

    /** The stored draft as a validated working copy, or null when there is none (or it does not validate). @return array{filters: list<array{key: string, show: bool}>, kinds: array<string, string>}|null */
    public static function draft(): ?array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY);
        $stored = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        if (! is_array($stored)) {
            return null;
        }

        [$clean, $errors] = SidebarFilters::validate($stored, app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());

        return $errors === [] ? $clean : null;
    }

    public static function hasUnpublishedChanges(): bool
    {
        $draft = self::draft();

        return $draft !== null && $draft != self::values();
    }

    /** «Приложи». @param  array<string, mixed>  $clean  VALIDATED */
    public static function saveDraft(array $clean): void
    {
        $settings = app(SiteSettingsRepository::class);

        $clean == self::values() ? $settings->forget(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY) : $settings->set(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY, (string) json_encode($clean, JSON_THROW_ON_ERROR));

        self::log('draft');
    }

    public static function discard(): void
    {
        app(SiteSettingsRepository::class)->forget(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY);

        self::log('discarded');
    }

    /**
     * «Публикувай»: the list is written, the three kinds are put into the published «Каталог» values (the other «Каталог» choices and its own draft are NOT touched) and the draft is forgotten. The appearance is
     * recompiled by the «Каталог» save, so the cache already holds the new list.
     *
     * @param  array<string, mixed>  $clean  VALIDATED
     */
    public static function publish(array $clean): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(AppearanceSettings::SIDEBAR_FILTERS_KEY, (string) json_encode(['filters' => $clean['filters']], JSON_THROW_ON_ERROR));

        $catalog = PageDesignStore::values(PageDesign::CATALOG);
        $catalog['filterBrand'] = $clean['kinds']['brand'];
        $catalog['filterSeason'] = $clean['kinds']['season'];
        $catalog['filterTag'] = $clean['kinds']['tag'];
        PageDesignStore::save(PageDesign::CATALOG, $catalog);

        $settings->forget(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY);

        self::log('published');
    }

    private static function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_sidebar', 'sidebar', $action, null, 'saved');
    }
}
