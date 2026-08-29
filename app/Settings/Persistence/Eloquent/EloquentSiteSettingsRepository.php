<?php

namespace App\Settings\Persistence\Eloquent;

use App\Settings\Contracts\SiteSettingsRepository;
use InvalidArgumentException;

/**
 * Maps the site_settings table directly onto get/set/forget — no
 * domain entity round-tripping (site-settings-design.md §6): `key` is
 * the natural, unique identity, so set() is a plain upsert-by-key
 * rather than the insert-vs-update-by-surrogate-id pattern every other
 * repository in this project follows.
 */
final class EloquentSiteSettingsRepository implements SiteSettingsRepository
{
    public function get(string $key): ?string
    {
        return SiteSettingModel::where('key', $key)->value('value');
    }

    /**
     * "key is a non-empty string" is the only invariant this concept
     * has (§6) — enforced trivially here rather than in a dedicated
     * domain entity, per the design doc's own reasoning.
     */
    public function set(string $key, string $value): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Site setting key must not be empty.');
        }

        SiteSettingModel::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function forget(string $key): void
    {
        SiteSettingModel::where('key', $key)->delete();
    }
}
