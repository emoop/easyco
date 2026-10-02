<?php

namespace App\Settings;

use App\Settings\Exceptions\CountryDataUnavailableException;
use Collator;
use ResourceBundle;

/**
 * ISO 3166-1 alpha-2 country codes with their names in a given locale, from
 * ext-intl's own CLDR data — no new composer dependency and no hand-written
 * list of names.
 *
 * The CLDR "Countries" table also holds codes that are not countries: 13
 * pseudo-regions and special codes (AC, CP, DG, EA, EU, EZ, IC, QO, TA, UN, XA,
 * XB, ZZ) are EXCLUDED.
 *
 * KOSOVO (XK) IS INCLUDED, deliberately. It is a user-assigned code, not an
 * ISO 3166-1 assignment, but the EU, banks and carriers all use it, and the
 * same list is reused for customer delivery addresses (stage 3.0b), where a
 * Kosovar customer must be able to pick their country. It is named from CLDR
 * like every other entry.
 *
 * The list is therefore "the 249 ISO 3166-1 codes plus XK", but nothing counts
 * on that number: a later ICU/CLDR update may add or retire a code, so callers
 * and tests rely on specific codes being present or absent, never on the size.
 *
 * If the ICU region data cannot be read at all — neither for the requested
 * locale nor for `en` — CountryDataUnavailableException is thrown instead of a
 * TypeError from iterating nothing.
 */
final class CountryNames
{
    public const BUNDLE = 'ICUDATA-region';

    /** CLDR region codes that are not countries: excluded from the list. */
    private const NOT_A_COUNTRY = ['AC', 'CP', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'ZZ'];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * @param  string  $bundleName  the ICU resource package to read; only a test
     *                              passes anything but the default (to prove the
     *                              missing-data failure without touching ICU)
     * @return array<string, string> code => name in $locale, sorted by name in that locale
     *
     * @throws CountryDataUnavailableException
     */
    public static function forLocale(string $locale, string $bundleName = self::BUNDLE): array
    {
        $cacheKey = $bundleName.'|'.$locale;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $countries = self::countriesTable($locale, $bundleName) ?? self::countriesTable('en', $bundleName);

        if ($countries === null) {
            throw CountryDataUnavailableException::for($locale, $bundleName);
        }

        $names = [];

        foreach ($countries as $code => $name) {
            $code = (string) $code;

            if (preg_match('/^[A-Z]{2}$/D', $code) === 1 && ! in_array($code, self::NOT_A_COUNTRY, true)) {
                $names[$code] = (string) $name;
            }
        }

        (new Collator($locale))->asort($names);

        return self::$cache[$cacheKey] = $names;
    }

    public static function isKnownCode(string $code): bool
    {
        return preg_match('/^[A-Z]{2}$/D', $code) === 1 && isset(self::forLocale('en')[$code]);
    }

    private static function countriesTable(string $locale, string $bundleName): ?ResourceBundle
    {
        $bundle = @ResourceBundle::create($locale, $bundleName);
        $table = $bundle?->get('Countries');

        return $table instanceof ResourceBundle ? $table : null;
    }
}
