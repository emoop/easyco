<?php

namespace App\Support;

/**
 * ORD-PC: Bulgarian postcodes → settlements → regions, from the committed data file `resources/data/bg-postcodes.php` (GeoNames postal codes, CC BY 4.0; rebuilt by
 * scripts/data/build-bg-postcodes.php). An in-memory read, loaded lazily the first time it is asked and kept for the PROCESS (the data never changes at runtime):
 * no query, no network. Region NAMES come from BgRegions (28 oblasti, keyed by the GeoNames admin1 code), never from the file.
 *
 * The same normalisation decides every name comparison: trimmed, lower-cased, a leading «гр.» / «с.» / «град» / «село» dropped, Bulgarian STREAMLINED transliteration to
 * Latin (so «Харманли» = «Harmanli», and a word-final «ия» is «ia»: «София» = «Sofia»), everything but letters and digits removed.
 */
final class BgPostcodes
{
    /** @var array<string, list<array{0: string, 1: string}>>|null */
    private static ?array $byPostcode = null;

    /** @var array<string, list<string>>|null normalised settlement name => distinct region codes */
    private static ?array $bySettlement = null;

    private const LATIN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sht',
        'ъ' => 'a', 'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
    ];

    public static function normalise(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/^(гр\.|гр\s|с\.|град\s|село\s)\s*/u', '', $name);
        // the streamlined system's one context rule: «ия» at the END of a word is «ia» (София = Sofia, Мария = Maria), everywhere else «iya»
        $name = (string) preg_replace('/ия(?=[\s\-]|$)/u', 'ia', $name);
        $name = strtr($name, self::LATIN);

        return (string) preg_replace('/[^a-z0-9]+/', '', $name);
    }

    /** a typed region («област Хасково», «Haskovo oblast»...) reduced to the name part */
    public static function normaliseRegion(string $region): string
    {
        $region = mb_strtolower(trim($region));
        $region = (string) preg_replace('/(^|\s)(област|обл\.?|oblast|obl\.?|region|province)(\s|$)/u', ' ', $region);

        return self::normalise($region);
    }

    public static function isPostcode(?string $postcode): bool
    {
        return $postcode !== null && preg_match('/^\d{4}$/', trim($postcode)) === 1;
    }

    /** @return list<string> the distinct settlement names (Bulgarian) of a postcode, in the data's order; [] = unknown code */
    public static function settlementsOf(string $postcode): array
    {
        return array_values(array_unique(array_column(self::postcodes()[trim($postcode)] ?? [], 0)));
    }

    /** @return list<string> the distinct region codes of a postcode; [] = unknown code */
    public static function regionCodesOf(string $postcode): array
    {
        return array_values(array_unique(array_column(self::postcodes()[trim($postcode)] ?? [], 1)));
    }

    /**
     * The region codes of a postcode narrowed by the settlement name when the name is one of that code's settlements (a postcode shared by two regions is
     * then settled by the town); the whole set otherwise.
     *
     * @return list<string>
     */
    public static function regionCodesOfPostcodeAndName(string $postcode, ?string $name): array
    {
        if ($name !== null && trim($name) !== '') {
            $key = self::normalise($name);
            $narrow = [];

            foreach (self::postcodes()[trim($postcode)] ?? [] as [$settlement, $code]) {
                if (self::normalise($settlement) === $key) {
                    $narrow[$code] = $code;
                }
            }

            if ($narrow !== []) {
                return array_values($narrow);
            }
        }

        return self::regionCodesOf($postcode);
    }

    /** whether the name is one of the postcode's settlements (after the normalisation) */
    public static function postcodeHasSettlement(string $postcode, string $name): bool
    {
        $key = self::normalise($name);

        foreach (self::postcodes()[trim($postcode)] ?? [] as [$settlement]) {
            if (self::normalise($settlement) === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the distinct region codes of every settlement with this (normalised) name, across the whole country */
    public static function regionCodesOfSettlement(string $name): array
    {
        if (self::$bySettlement === null) {
            $index = [];

            foreach (self::postcodes() as $pairs) {
                foreach ($pairs as [$settlement, $code]) {
                    $index[self::normalise($settlement)][$code] = $code;
                }
            }

            self::$bySettlement = array_map('array_values', $index);
        }

        $key = self::normalise($name);

        return $key === '' ? [] : (self::$bySettlement[$key] ?? []);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string> the region NAMES, sorted
     */
    public static function regionNames(array $codes): array
    {
        $names = array_values(array_filter(array_map(static fn (string $code): ?string => BgRegions::name($code), $codes)));
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return array<string, list<array{0: string, 1: string}>> */
    private static function postcodes(): array
    {
        return self::$byPostcode ??= (require dirname(__DIR__, 2).'/resources/data/bg-postcodes.php')['p'];
    }

    /** every code of the data file (for the table test) @return list<string> */
    public static function allRegionCodes(): array
    {
        return array_values(array_unique(array_merge(...array_map(static fn (array $pairs): array => array_column($pairs, 1), self::postcodes()))));
    }
}
