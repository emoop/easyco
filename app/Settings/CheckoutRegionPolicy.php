<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Exceptions\CheckoutRegionPolicyMalformedException;
use InvalidArgumentException;

/**
 * THE ONE READER of the region (state / province) rule per country (checkout RN-1, checkout-region-and-note-design.md 2.2-2.3).
 * Every consumer — the checkout request, the address API, the storefront page, the admin editor — asks this class.
 *
 * Two keys, both owned here (site-settings-design.md section 5: the owner of a key owns its default and its validation):
 *  - `checkout.region_default`: `optional` | `required` | `hidden`; ABSENT = `optional` (the owner's "shown by default");
 *  - `checkout.region_countries`: a JSON OBJECT {ISO2: mode}; ABSENT = {} (no exception).
 *
 * A MALFORMED stored value — not JSON, not an object, an unknown mode, an unknown country, a non-string — THROWS
 * CheckoutRegionPolicyMalformedException (rule 8: fail loud, never a silent default). The admin page cannot save such a value: it
 * builds what it stores with normalizeCountries() / encodeCountries(), the same rules this reader applies.
 */
final class CheckoutRegionPolicy
{
    public const DEFAULT_KEY = 'checkout.region_default';

    public const COUNTRIES_KEY = 'checkout.region_countries';

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    /**
     * BOTH stored values, read together (checkout RN-1b): ONE query for the two keys, and the second caller of the request is served by the
     * repository's memo, so asking for the mode of a country costs exactly one query per request.
     *
     * @return array<string, ?string>
     */
    private function raw(): array
    {
        return $this->settings->getMany([self::DEFAULT_KEY, self::COUNTRIES_KEY]);
    }

    /** The mode for a delivery country (any case; a code the owner never configured gets the default). @throws CheckoutRegionPolicyMalformedException */
    public function modeFor(string $country): RegionMode
    {
        return $this->overrides()[strtoupper(trim($country))] ?? $this->defaultMode();
    }

    /** @throws CheckoutRegionPolicyMalformedException */
    public function defaultMode(): RegionMode
    {
        $raw = $this->raw()[self::DEFAULT_KEY];

        if ($raw === null || $raw === '') {
            return RegionMode::OPTIONAL;
        }

        return RegionMode::tryFrom($raw) ?? throw CheckoutRegionPolicyMalformedException::because(self::DEFAULT_KEY, 'it is not optional, required or hidden');
    }

    /**
     * The per-country exceptions.
     *
     * @return array<string, RegionMode> upper-case ISO code => mode
     *
     * @throws CheckoutRegionPolicyMalformedException
     */
    public function overrides(): array
    {
        $raw = $this->raw()[self::COUNTRIES_KEY];

        if ($raw === null || $raw === '') {
            return [];
        }

        try {
            return self::parseCountries($raw);
        } catch (InvalidArgumentException $e) {
            throw CheckoutRegionPolicyMalformedException::because(self::COUNTRIES_KEY, $e->getMessage());
        }
    }

    /**
     * Default and exceptions together, as plain strings (for the storefront page's data attribute).
     *
     * @return array{default: string, countries: array<string, string>}
     */
    public function all(): array
    {
        return [
            'default' => $this->defaultMode()->value,
            'countries' => array_map(static fn (RegionMode $mode): string => $mode->value, $this->overrides()),
        ];
    }

    /**
     * The stored JSON object as a map, with the reader's own rules.
     *
     * @return array<string, RegionMode>
     *
     * @throws InvalidArgumentException with the reason
     */
    public static function parseCountries(string $json): array
    {
        $decoded = json_decode($json, false);

        if (! $decoded instanceof \stdClass) {
            throw new InvalidArgumentException('it is not a JSON object of country => mode');
        }

        $rows = [];

        foreach (get_object_vars($decoded) as $country => $mode) {
            $rows[] = ['country' => (string) $country, 'mode' => $mode];
        }

        return self::normalizeCountries($rows);
    }

    /**
     * THE ONE VALIDATION of the exceptions list, shared by the reader and the admin page: every country a KNOWN ISO code (upper-cased
     * here), at most once, every mode one of the three, written as a string.
     *
     * @param  list<array{country: mixed, mode: mixed}>  $rows
     * @return array<string, RegionMode>
     *
     * @throws InvalidArgumentException with the reason
     */
    public static function normalizeCountries(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $country = is_string($row['country'] ?? null) ? strtoupper(trim($row['country'])) : '';
            $mode = is_string($row['mode'] ?? null) ? RegionMode::tryFrom($row['mode']) : null;

            if (! CountryNames::isKnownCode($country)) {
                throw new InvalidArgumentException('a country is not a known ISO code');
            }

            if ($mode === null) {
                throw new InvalidArgumentException("the mode of {$country} is not optional, required or hidden");
            }

            if (isset($map[$country])) {
                throw new InvalidArgumentException("{$country} appears more than once");
            }

            $map[$country] = $mode;
        }

        return $map;
    }

    /** @param array<string, RegionMode> $map */
    public static function encodeCountries(array $map): string
    {
        ksort($map);

        return json_encode(array_map(static fn (RegionMode $mode): string => $mode->value, $map), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }
}
