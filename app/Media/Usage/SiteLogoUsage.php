<?php

namespace App\Media\Usage;

use App\Filament\Pages\Settings\LocaleSettings;
use App\Settings\SiteLogo;
use Illuminate\Database\Eloquent\Builder;

/**
 * LOGO-1: the LOGOS of the «Лого» tab: `logo.main` (the customer emails and the admin) and `logo.dark` (the admin's dark mode). A picture used as either is never deleted from «Медия». One read of
 * two settings rows for a whole page of ids. (The header and checkout-header logos are «Дизайн» zones: DesignZones.)
 */
final class SiteLogoUsage implements MediaUsageProvider
{
    private const KEYS = [SiteLogo::MAIN => 'site_logo', SiteLogo::DARK => 'site_logo_dark'];

    public function counts(array $ids): array
    {
        $counts = [];

        foreach ($this->ids() as $id) {
            in_array($id, $ids, true) && $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }

    public function usagesOf(int $id): array
    {
        $usages = [];

        foreach ($this->ids() as $key => $used) {
            $used === $id && $usages[] = new MediaUsage('site_logo', __('media.usage.'.self::KEYS[$key]), LocaleSettings::getUrl());
        }

        return $usages;
    }

    public function excludeUsed(Builder $query): void
    {
        $used = array_values(array_unique($this->ids()));

        $used !== [] && $query->whereNotIn('catalog_media.id', $used);
    }

    /** @return array<string, int> setting key => the picture id it holds */
    private function ids(): array
    {
        $out = [];

        // one `getMany` for the three keys, shared with SiteIconUsage through the repository's memo
        $stored = app(\App\Settings\Contracts\SiteSettingsRepository::class)->getMany([SiteLogo::ICON, SiteLogo::MAIN, SiteLogo::DARK]);

        foreach (array_intersect_key($stored, self::KEYS) as $key => $value) {
            is_string($value) && ctype_digit($value) && (int) $value > 0 && $out[(string) $key] = (int) $value;
        }

        return $out;
    }
}
