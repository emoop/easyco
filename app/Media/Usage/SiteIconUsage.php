<?php

namespace App\Media\Usage;

use App\Filament\Pages\Settings\LocaleSettings;
use App\Settings\SiteLogo;
use Illuminate\Database\Eloquent\Builder;

/**
 * LOGO-1: the SITE ICON of the «Лого» tab (`site_icon.media_id`). A picture the site shows as its icon is never deleted from «Медия». One read of one settings row for a whole page of ids.
 */
final class SiteIconUsage implements MediaUsageProvider
{
    public function counts(array $ids): array
    {
        $id = $this->id();

        return $id !== null && in_array($id, $ids, true) ? [$id => 1] : [];
    }

    public function usagesOf(int $id): array
    {
        return $this->id() === $id ? [new MediaUsage('site_icon', __('media.usage.site_icon'), LocaleSettings::getUrl())] : [];
    }

    public function excludeUsed(Builder $query): void
    {
        $id = $this->id();

        $id !== null && $query->where('catalog_media.id', '!=', $id);
    }

    private function id(): ?int
    {
        // one `getMany` for the three keys: the repository's memo serves SiteLogoUsage with no query of its own
        $value = app(\App\Settings\Contracts\SiteSettingsRepository::class)->getMany([SiteLogo::ICON, SiteLogo::MAIN, SiteLogo::DARK])[SiteLogo::ICON];

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
