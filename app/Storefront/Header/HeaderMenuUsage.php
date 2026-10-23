<?php

namespace App\Storefront\Header;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * Is a menu chosen EXPLICITLY by a `menu` element of the PUBLISHED or the DRAFT header (DS-2a.1)? The Menus editor refuses to delete such a menu, like it refuses one that holds a location
 * (the same shape as HtmlBlockUsage::footer for the footer). Admin only: the layouts are read through the settings repository and are not validated here (a bad stored one simply names nothing).
 */
final class HeaderMenuUsage
{
    public static function uses(int $menuId): bool
    {
        $settings = app(SiteSettingsRepository::class);

        foreach ([AppearanceSettings::HEADER_PUBLISHED_KEY, AppearanceSettings::HEADER_DRAFT_KEY] as $key) {
            $json = $settings->get($key);
            $layout = is_string($json) ? json_decode($json, true) : null;

            foreach (['desktop', 'mobile'] as $variant) {
                $part = is_array($layout[$variant] ?? null) ? $layout[$variant] : [];
                $elements = is_array($part['drawer'] ?? null) ? $part['drawer'] : [];

                foreach (is_array($part['rows'] ?? null) ? $part['rows'] : [] as $row) {
                    foreach (HeaderLayout::SLOTS as $slot) {
                        $elements = [...$elements, ...(is_array($row[$slot] ?? null) ? $row[$slot] : [])];
                    }
                }

                foreach ($elements as $element) {
                    $id = is_array($element) && ($element['type'] ?? null) === 'menu' && is_array($element['settings'] ?? null) ? ($element['settings']['menu'] ?? null) : null;

                    if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id === $menuId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
