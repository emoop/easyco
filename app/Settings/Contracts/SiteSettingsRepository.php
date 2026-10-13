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

    /**
     * Several settings at once (checkout RN-1b): the same answer as calling get() for each key, but a repository that can may read
     * all the keys it does not already know with ONE query. The result has an entry for EVERY requested key; a key that is not set
     * is null. An empty list is an empty result.
     *
     * @param  list<string>  $keys
     * @return array<string, ?string>
     */
    public function getMany(array $keys): array;

    public function set(string $key, string $value): void;

    public function forget(string $key): void;
}
