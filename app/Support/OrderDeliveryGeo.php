<?php

namespace App\Support;

/**
 * ORD-PC: what the ADMIN order view adds to the delivery block from the postcode data: the region and the notes. Admin only — the storefront page, the mail and the API
 * never call this class. Pure (no query): BgPostcodes reads the committed data file. Texts come back as plain text (the caller escapes).
 *
 * STREET ADDRESS (country BG, a 4-digit postcode): the region of the postcode (narrowed by the city when the code is shared by two regions); a note when the city is not
 * one of the code's settlements; a note when the customer typed a region that differs from the derived one. OFFICE / LOCKER (no postcode is stored for them, and none
 * is ever invented): the region derived from the SETTLEMENT NAME alone — one region (with a «(по населеното място)» suffix), several («Възможни области: …») or none.
 */
final class OrderDeliveryGeo
{
    public const MAX_LISTED = 5;

    /**
     * @return array{region: ?string, possible: ?string, notes: list<string>}
     *                                                                         region: the one derived region name; possible: «A, B, C» when the code/name fits several regions
     */
    public static function forStreet(?string $country, ?string $city, ?string $postcode, ?string $typedRegion, string $locale): array
    {
        $none = ['region' => null, 'possible' => null, 'notes' => []];

        if ($country !== 'BG' || ! BgPostcodes::isPostcode($postcode)) {
            return $none;
        }

        $code = trim((string) $postcode);
        $settlements = BgPostcodes::settlementsOf($code);

        if ($settlements === []) {
            return $none;
        }

        $names = BgPostcodes::regionNames(BgPostcodes::regionCodesOfPostcodeAndName($code, $city));
        $result = $none;
        $result['region'] = count($names) === 1 ? $names[0] : null;
        $result['possible'] = count($names) > 1 ? self::list($names) : null;

        if (filled($city) && ! BgPostcodes::postcodeHasSettlement($code, (string) $city)) {
            $result['notes'][] = (string) __('orders.geo.other_settlement', ['list' => self::list($settlements)], $locale);
        }

        if ($result['region'] !== null && filled($typedRegion) && ! self::sameRegion((string) $typedRegion, $result['region'])) {
            $result['notes'][] = (string) __('orders.geo.typed_region', ['region' => trim((string) $typedRegion)], $locale);
        }

        return $result;
    }

    /**
     * @return array{region: ?string, possible: ?string}
     */
    public static function forPickup(?string $country, ?string $settlement): array
    {
        if ($country !== 'BG' || ! filled($settlement)) {
            return ['region' => null, 'possible' => null];
        }

        $names = BgPostcodes::regionNames(BgPostcodes::regionCodesOfSettlement((string) $settlement));

        return [
            'region' => count($names) === 1 ? $names[0] : null,
            'possible' => count($names) > 1 ? self::list($names) : null,
        ];
    }

    /** the typed region matches the derived one: the same name after the normalisation, or «София» for «София-град» / «София област» (the typed word is the start of the name) */
    private static function sameRegion(string $typed, string $derived): bool
    {
        $typedKey = BgPostcodes::normaliseRegion($typed);
        $derivedKey = BgPostcodes::normaliseRegion($derived);

        return $typedKey !== '' && ($typedKey === $derivedKey || str_starts_with($derivedKey, $typedKey));
    }

    /** @param  list<string>  $items */
    private static function list(array $items): string
    {
        $shown = array_slice($items, 0, self::MAX_LISTED);

        return implode(', ', $shown).(count($items) > self::MAX_LISTED ? ', …' : '');
    }
}
