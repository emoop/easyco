<?php

namespace App\Settings\Contracts;

/**
 * Deliberately thin — see
 * packages/EasyCo/documents/site-settings-design.md §5. No built-in
 * fallback-to-config logic: a generic settings mechanism can't know,
 * per key, what an appropriate code-level default is or where it
 * lives — that's the calling code's responsibility, e.g.
 * `$repository->get('media.hero_slider_enabled') ??
 * config('services.media.hero_slider_enabled_default', 'true')`.
 */
interface SiteSettingsRepository
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function forget(string $key): void;
}
