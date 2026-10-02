<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;

/**
 * THE ONE READER of the store's locale (`site.locale`), with ONE fallback:
 * `config('app.locale')`.
 *
 * Before this class the key was read in two places with two different
 * fallbacks — ApplyStoreLocale fell back to config('app.locale') ('en' in this
 * project) while the settings page fell back to a hard-coded 'bg' — so with no
 * stored row the panel rendered in English while the form claimed Bulgarian.
 * Both now ask here, so "what is the store's locale when nothing is stored" has
 * exactly one answer (CLAUDE.md rule 8: configurable, no silent second guess).
 *
 * It does NOT apply the locale: ApplyStoreLocale does that, on the `web`
 * middleware group only. The `api` group never runs it, so an API endpoint that
 * needs the store's locale (the shipping quote endpoint, stage 3d) calls
 * current() explicitly instead of trusting App::getLocale().
 */
final class StoreLocale
{
    public const KEY = 'site.locale';

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    public function current(): string
    {
        return $this->settings->get(self::KEY) ?? (string) config('app.locale');
    }
}
