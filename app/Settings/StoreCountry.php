<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Exceptions\StoreCountryNotConfiguredException;

/**
 * THE ONE READER of the store's country (`site.country`): an ISO 3166-1 alpha-2
 * code, uppercase. It is the prerequisite for anything that has to know where
 * the shop is — for example the country of a pickup-point delivery, whose
 * address carries none (shipping-domain-design.md §4).
 *
 * current() THROWS when the setting is unset or malformed (fail loud, CLAUDE.md
 * rule 8): there is deliberately no default country. currentOrNull() exists for
 * UI defaults only (the settings form showing "nothing chosen yet"); no
 * business rule may call it.
 */
final class StoreCountry
{
    public const KEY = 'site.country';

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    /** @throws StoreCountryNotConfiguredException */
    public function current(): string
    {
        $value = $this->settings->get(self::KEY);

        if ($value === null || $value === '') {
            throw StoreCountryNotConfiguredException::unset();
        }

        if (! CountryNames::isKnownCode($value)) {
            throw StoreCountryNotConfiguredException::invalid($value);
        }

        return $value;
    }

    /** For UI defaults only: the stored code, or null when unset or malformed. */
    public function currentOrNull(): ?string
    {
        $value = $this->settings->get(self::KEY);

        return $value !== null && CountryNames::isKnownCode($value) ? $value : null;
    }
}
