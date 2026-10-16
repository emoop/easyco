<?php

namespace App\Storefront\Header;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;

/**
 * Rebuilds the ONE token stylesheet from what is STORED (the appearance overrides + the published header's sizes), stores `appearance.compiled`, and
 * replaces the reader's cache entry. Used by the header's publish; the Appearance page's own save already compiles with the stored header sizes
 * (AppearanceCompiler reads them), so the two never drop each other's values from the stylesheet.
 */
final class AppearanceRecompiler
{
    public function run(SiteSettingsRepository $settings): void
    {
        $defaults = AppearanceSettings::defaults();
        $stored = $settings->getMany([...array_keys($defaults), AppearanceSettings::COMPILED_KEY]);
        $overrides = [];

        foreach ($defaults as $key => $default) {
            $value = $stored[$key] ?? null;

            if (is_string($value) && AppearanceSettings::isValid($key, strtolower($value)) && strtolower($value) !== $default) {
                $overrides[$key] = strtolower($value);
            }
        }

        $previous = StorefrontAppearance::build([AppearanceSettings::COMPILED_KEY => $stored[AppearanceSettings::COMPILED_KEY] ?? null])['compiled']['hash'] ?? null;
        $compiled = app(AppearanceCompiler::class)->compile($overrides, $previous);

        $compiled === null ? $settings->forget(AppearanceSettings::COMPILED_KEY) : $settings->set(AppearanceSettings::COMPILED_KEY, (string) json_encode($compiled, JSON_THROW_ON_ERROR));

        app(StorefrontAppearance::class)->refresh();
    }
}
