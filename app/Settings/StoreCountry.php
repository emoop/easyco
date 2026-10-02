<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Exceptions\StoreCountryNotConfiguredException;

/**
 * THE ONE READER of the store's country (`site.country`): an ISO 3166-1 alpha-2
 * code, uppercase: where the shop is.
 *
 * IT IS NOT A DELIVERY COUNTRY. Every address and order — a PICKUP_POINT
 * included — carries its own validated country (owner decision D1,
 * shipping-domain-design.md §4); the store country is at most the default a
 * storefront form PRESELECTS, and no business rule ever substitutes it for a
 * missing delivery country.
 *
 * current() THROWS when the setting is unset or malformed (fail loud, CLAUDE.md
 * rule 8): there is deliberately no default country. currentOrNull() exists for
 * UI defaults only (the settings form showing "nothing chosen yet", a form's
 * preselected country); no business rule may call it.
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
