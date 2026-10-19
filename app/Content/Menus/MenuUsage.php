<?php

namespace App\Content\Menus;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;

/** Where the header uses a menu (its published layout and its draft): a menu in use is never deleted. */
final class MenuUsage
{
    public function __construct(private readonly SiteSettingsRepository $settings)
    {
    }

    /** @return list<string> the places, in the admin's language (empty = not used) */
    public function places(string $key): array
    {
        $places = [];

        foreach ([AppearanceSettings::HEADER_PUBLISHED_KEY => 'published', AppearanceSettings::HEADER_DRAFT_KEY => 'draft'] as $setting => $name) {
            $json = $this->settings->get($setting);
            $layout = is_string($json) ? json_decode($json, true) : null;

            if (is_array($layout) && $this->uses($layout, $key)) {
                $places[] = __('menus.places.'.$name);
            }
        }

        return $places;
    }

    /** @param  array<string, mixed>  $layout */
    private function uses(array $layout, string $key): bool
    {
        $elements = [];

        foreach (['desktop', 'mobile'] as $name) {
            foreach ((array) ($layout[$name]['rows'] ?? []) as $row) {
                foreach (['left', 'center', 'right'] as $slot) {
                    array_push($elements, ...array_values((array) ($row[$slot] ?? [])));
                }
            }
        }

        array_push($elements, ...array_values((array) ($layout['mobile']['drawer'] ?? [])));

        foreach ($elements as $element) {
            if (is_array($element) && ($element['type'] ?? null) === 'menu' && ($element['settings']['source'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }
}
