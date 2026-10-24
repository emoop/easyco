<?php

namespace App\Media\Usage;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The «Дизайн» zones (header, footer, the cart and checkout header): their DRAFT and PUBLISHED settings keys, read in ONE query. Today no zone stores a picture, so this finds nothing; it is the
 * place DS-4 fills: a picture a zone keeps is a node (or a field) carrying `media_asset_id` (or `media_id`) with the asset id, anywhere in the JSON. A draft counts as use (labelled «(чернова)»):
 * the picture is not deleted from under a layout nobody has published yet.
 */
final class DesignZones implements MediaUsageProvider
{
    /** zone => [setting key => is a draft] */
    private const KEYS = [
        'header' => [AppearanceSettings::HEADER_DRAFT_KEY => true, AppearanceSettings::HEADER_PUBLISHED_KEY => false],
        'footer' => [AppearanceSettings::FOOTER_DRAFT_KEY => true, AppearanceSettings::FOOTER_PUBLISHED_KEY => false],
        'checkout' => [AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY => true, AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY => false],
    ];

    /** @var list<array{zone: string, draft: bool, id: int}>|null */
    private ?array $found = null;

    public function counts(array $ids): array
    {
        $wanted = array_flip($ids);
        $counts = [];

        foreach ($this->found() as $use) {
            isset($wanted[$use['id']]) && $counts[$use['id']] = ($counts[$use['id']] ?? 0) + 1;
        }

        return $counts;
    }

    public function usagesOf(int $id): array
    {
        $usages = [];

        foreach ($this->found() as $use) {
            $use['id'] === $id && $usages[] = new MediaUsage('design', __('media.usage.design', ['zone' => __('media.usage.zone_'.$use['zone'])]).($use['draft'] ? ' '.__('media.usage.draft') : ''), DesignScreen::getUrl());
        }

        return $usages;
    }

    public function excludeUsed(Builder $query): void
    {
        $used = array_values(array_unique(array_column($this->found(), 'id')));

        $used !== [] && $query->whereNotIn('catalog_media.id', $used);
    }

    /** @return list<array{zone: string, draft: bool, id: int}> */
    private function found(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }

        $keys = [];
        foreach (self::KEYS as $zone => $pairs) {
            foreach ($pairs as $key => $draft) {
                $keys[$key] = [$zone, $draft];
            }
        }

        $values = app(SiteSettingsRepository::class)->getMany(array_keys($keys));
        $found = [];

        foreach ($keys as $key => [$zone, $draft]) {
            $json = $values[$key] ?? null;
            $data = is_string($json) ? json_decode($json, true) : null;

            foreach (is_array($data) ? $this->ids($data) : [] as $id) {
                $found[] = ['zone' => $zone, 'draft' => $draft, 'id' => $id];
            }
        }

        return $this->found = $found;
    }

    /** @return list<int> every `media_asset_id` / `media_id` in the JSON, at any depth */
    private function ids(array $node): array
    {
        $ids = [];

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $ids = array_merge($ids, $this->ids($value));
            } elseif (in_array($key, ['media_asset_id', 'media_id'], true) && (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
