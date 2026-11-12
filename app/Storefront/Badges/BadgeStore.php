<?php

namespace App\Storefront\Badges;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;

/**
 * P7-1: what the «Значки» zone stores, in one place (the pattern of SidebarStore). A PUBLISHED, COMPILED copy (`design.badges.global`: only the ENABLED rows; the key does NOT exist while no badge is on, so a shop that
 * never used the zone is exactly what it was) read by the storefront with the rest of the appearance (ONE `getMany`, no query of its own), and beside it a DRAFT (the working copy: every built-in row).
 * «Приложи» writes the draft only; «Публикувай» writes the compiled copy (or forgets the key), forgets the draft and replaces the appearance cache entry; «Върни публикуваното» forgets the draft. A draft equal to what is
 * published is never kept.
 */
final class BadgeStore
{
    /** The published working copy: the stored enabled rows over the defaults (every built-in OFF). @return array{badges: list<array<string, mixed>>} */
    public static function values(): array
    {
        return BadgeSet::workingFrom(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_KEY));
    }

    /** The working copy the screen starts from: the draft when there is one, else the published one. @return array{badges: list<array<string, mixed>>} */
    public static function startingValues(): array
    {
        return self::draft() ?? self::values();
    }

    /** The stored draft as a validated working copy, or null when there is none (or it does not validate). @return array{badges: list<array<string, mixed>>}|null */
    public static function draft(): ?array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_DRAFT_KEY);
        $stored = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        if (! is_array($stored)) {
            return null;
        }

        [$clean, $errors] = BadgeSet::validate($stored);

        return $errors === [] ? $clean : null;
    }

    public static function hasUnpublishedChanges(): bool
    {
        $draft = self::draft();

        return $draft !== null && $draft != self::values();
    }

    /** «Приложи». @param  array{badges: list<array<string, mixed>>}  $clean  VALIDATED */
    public static function saveDraft(array $clean): void
    {
        $settings = app(SiteSettingsRepository::class);

        $clean == self::values() ? $settings->forget(AppearanceSettings::BADGES_DRAFT_KEY) : $settings->set(AppearanceSettings::BADGES_DRAFT_KEY, (string) json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        self::log('draft');
    }

    public static function discard(): void
    {
        app(SiteSettingsRepository::class)->forget(AppearanceSettings::BADGES_DRAFT_KEY);

        self::log('discarded');
    }

    /**
     * «Публикувай»: the compiled copy is written (the enabled rows; NOTHING when none is enabled: the key is forgotten), the draft is forgotten and the appearance cache entry is REPLACED, so the next request already
     * shows the new set.
     *
     * @param  array{badges: list<array<string, mixed>>}  $clean  VALIDATED
     */
    public static function publish(array $clean): void
    {
        $settings = app(SiteSettingsRepository::class);
        $compiled = BadgeSet::compile($clean);

        $compiled === null ? $settings->forget(AppearanceSettings::BADGES_KEY) : $settings->set(AppearanceSettings::BADGES_KEY, $compiled);
        $settings->forget(AppearanceSettings::BADGES_DRAFT_KEY);

        app(StorefrontAppearance::class)->refresh();

        self::log('published');
    }

    private static function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_badges', 'badges', $action, null, 'saved');
    }
}
