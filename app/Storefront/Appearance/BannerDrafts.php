<?php

namespace App\Storefront\Appearance;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * DS-5: the top banner's DRAFT (the working copy), beside its published storage (`banner.*` keys and the two colour overrides, written by AppearanceWriter). One JSON setting, `banner.draft`: the
 * values the «Топбанер» zone of the Design screen validated (`enabled`, `text`, `url`, `link_label`, `text_color`, `bg_color`, `starts_at`, `ends_at`). The storefront NEVER reads it; only the admin's
 * signed preview does, and it prints it through the very parser that guards the published banner (StorefrontAppearance::bannerFromValues), so nothing unsafe can be shown.
 */
final class BannerDrafts
{
    /** The stored draft, decoded (NOT validated: the zone validates, the preview re-parses), or null. @return array<string, mixed>|null */
    public static function raw(): ?array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::BANNER_DRAFT_KEY);
        $values = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($values) ? $values : null;
    }

    /** @param  array<string, mixed>  $values  validated by the zone */
    public static function save(array $values): void
    {
        app(SiteSettingsRepository::class)->set(AppearanceSettings::BANNER_DRAFT_KEY, (string) json_encode($values, JSON_THROW_ON_ERROR));
        self::log('draft');
    }

    /** «Върни публикуваното» (and «Публикувай», which turns the draft into the published banner): the draft is forgotten. */
    public static function forget(string $action = 'discarded'): void
    {
        app(SiteSettingsRepository::class)->forget(AppearanceSettings::BANNER_DRAFT_KEY);
        self::log($action);
    }

    private static function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_appearance', 'appearance', 'banner_'.$action, null, 'saved');
    }
}
