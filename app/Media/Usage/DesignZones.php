<?php

namespace App\Media\Usage;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The «Дизайн» zones (header, footer, the cart and checkout header): their DRAFT and PUBLISHED settings keys, read in ONE query. A picture a zone keeps is a node (or a field) carrying `media_asset_id`
 * (or `media_id`) with the asset id, anywhere in the JSON — and, since LG-1, the LOGO picture: the header's `logo` node (`settings.image`) and the checkout header's `logo_image`. Both sit in the zones'
 * own stored JSON (the header layout and the checkout header values, behind HeaderAdapter / CheckoutHeaderDrafts), draft and published, so the same keys are read; they are labelled «Дизайн — Хедър, лого»
 * and «Дизайн — Хедър на поръчката, лого». A draft counts as use (labelled «(чернова)»):
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

    /** @var list<array{zone: string, draft: bool, id: int, logo: bool}>|null */
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
            $zone = $use['logo'] && in_array($use['zone'], ['header', 'checkout'], true) ? 'zone_'.$use['zone'].'_logo' : 'zone_'.$use['zone'];

            $use['id'] === $id && $usages[] = new MediaUsage('design', __('media.usage.design', ['zone' => __('media.usage.'.$zone)]).($use['draft'] ? ' '.__('media.usage.draft') : ''), DesignScreen::getUrl());
        }

        return $usages;
    }

    public function excludeUsed(Builder $query): void
    {
        $used = array_values(array_unique(array_column($this->found(), 'id')));

        $used !== [] && $query->whereNotIn('catalog_media.id', $used);
    }

    /** @return list<array{zone: string, draft: bool, id: int, logo: bool}> */
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

            // one line per PLACE: the desktop and the phone logo of one header both name the same picture, «Къде се ползва» says it once
            foreach (is_array($data) ? $this->ids($data) : [] as [$id, $logo]) {
                $found[$zone.'|'.($draft ? 'd' : 'p').'|'.$id.'|'.($logo ? 'l' : 'g')] = ['zone' => $zone, 'draft' => $draft, 'id' => $id, 'logo' => $logo];
            }
        }

        return $this->found = array_values($found);
    }

    /**
     * Every `media_asset_id` / `media_id` in the JSON, at any depth, and the logo pictures: `logo_image` (the checkout header's value) and `image` of a `logo` node (the header's element).
     *
     * @return list<array{0: int, 1: bool}> [asset id, is a logo picture]
     */
    private function ids(array $node): array
    {
        $ids = [];
        $isLogoNode = ($node['type'] ?? null) === 'logo';

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $ids = array_merge($ids, $this->ids($value));

                continue;
            }

            $isId = (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;

            if ($isId && in_array($key, ['media_asset_id', 'media_id'], true)) {
                $ids[] = [(int) $value, false];
            } elseif ($isId && $key === 'logo_image') {
                $ids[] = [(int) $value, true];
            }
        }

        // the logo ELEMENT keeps its picture one level down, in `settings.image`
        if ($isLogoNode && is_array($node['settings'] ?? null)) {
            $image = $node['settings']['image'] ?? null;

            if ((is_int($image) || (is_string($image) && ctype_digit($image))) && (int) $image > 0) {
                $ids[] = [(int) $image, true];
            }
        }

        return $ids;
    }
}
