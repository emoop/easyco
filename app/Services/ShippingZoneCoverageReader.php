<?php

namespace App\Services;

use App\Settings\CountryNames;
use App\Settings\StoreLocale;
use EasyCo\Shipping\ShippingZone;

/**
 * The ONE reader of a zone's coverage sentence (shipping-domain-design.md §12.4): the countries by NAME in
 * the store's language, then the settlement / postcode narrowing — "Bulgaria; only: София, Пловдив; only
 * postcodes: 3". It was a private method of the overview page; the overview and the zones list now both
 * read it here, so the same zone can never be described two ways.
 *
 * It reads; it never matches and never writes. The country names are read at most ONCE per instance, so a
 * list of many zones stays at one lookup. A zone name, a settlement name is the merchant's own data and is
 * escaped where it is rendered.
 */
final class ShippingZoneCoverageReader
{
    /** @var array<string, string>|null code => name, in the store locale */
    private ?array $countryNames = null;

    public function __construct(
        private readonly StoreLocale $storeLocale,
    ) {
    }

    /** @param  array<string, string>|null  $countryNames  code => name, when the caller already holds the list */
    public function sentence(ShippingZone $zone, ?array $countryNames = null): string
    {
        return $this->sentenceFor($zone->countryCodes(), $zone->settlementNames(), $zone->postcodes(), $countryNames);
    }

    /**
     * The same sentence from the three stored lists (a list that reads the zones as table rows has no entity).
     *
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementNames
     * @param  list<string>|null  $postcodes
     * @param  array<string, string>|null  $countryNames
     */
    public function sentenceFor(array $countryCodes, ?array $settlementNames, ?array $postcodes, ?array $countryNames = null): string
    {
        $names = $countryNames ?? $this->countryNames();

        $parts = [implode(', ', array_map(
            static fn (string $code): string => $names[$code] ?? $code,
            $countryCodes,
        ))];

        if ($settlementNames !== null) {
            $parts[] = __('shipping.coverage.only_settlements', ['names' => implode(', ', $settlementNames)]);
        }

        if ($postcodes !== null) {
            $parts[] = __('shipping.coverage.only_postcodes', ['count' => count($postcodes)]);
        }

        return implode('; ', $parts);
    }

    /** @return array<string, string> */
    public function countryNames(): array
    {
        return $this->countryNames ??= CountryNames::forLocale($this->storeLocale->current());
    }
}
