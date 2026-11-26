<?php

namespace App\Couriers\Settlements;

use App\Support\BgPostcodes;
use App\Support\BgRegions;
use EasyCo\Shipping\Carrier\CourierSettlement;
use Illuminate\Support\Facades\DB;

/**
 * Builds `shipping_settlements` (courier-integration-design.md §3.3): the national backbone from the postcode file, then each
 * courier's own settlements joined onto it by RULES, never by guessing.
 *
 *  1. BACKBONE: the postcode data grouped by (region, normalised name). «Град София» is ONE row with all its postcodes.
 *  2. JOIN, in this order, per courier settlement:
 *       (a) the courier's postcode is in the row's postcodes AND the normalised names are equal: exactly one row -> match;
 *       (b) else the names are equal and the region is equal, and there is exactly ONE such region -> match (the region is the
 *           courier's region NAME read as oblast(s); it may be unreadable or ambiguous, then (b) does not apply);
 *       (c) else NO MATCH: a `source = courier` row of its own is made, and the report counts it.
 *     Never fuzzy, never by name alone across regions.
 *  3. SPLIT: when one row is matched by two different ids of ONE courier (two Speedy sites / Econt cities that share a name in a
 *     region) it really is two settlements: the row is split by postcode (a part per id, `part` = the postcode, or `x<id>` when the
 *     postcodes cannot tell them apart) and the base row is flagged `is_split` and loses that courier's id and the claimed postcodes.
 *
 * IDEMPOTENT: the result depends on the inputs and on rows already there only through the identity key, so the same input again
 * changes nothing (no write at all for an unchanged row). NOTHING IS DELETED (§3.5): a courier id that is no longer listed is
 * cleared from its row, the row stays. A courier whose list is not in the input keeps its ids untouched.
 *
 * The unique keys on the two id columns are respected by CLEARING the ids that change before the rows are written.
 */
final class SettlementBuilder
{
    public const COUNTRY = 'BG';

    /** carrier code => the id column of shipping_settlements */
    public const COLUMNS = ['speedy' => 'speedy_site_id', 'econt' => 'econt_city_id'];

    private const PREFIX = ['speedy' => 's', 'econt' => 'e'];

    /** Names the couriers use for the capital's oblast, as {@see BgPostcodes::normaliseRegion()} sees them. */
    private const SOFIA_CITY = ['sofiagrad', 'sofiastolitsa', 'sofiastolichna', 'sofiacity', 'sofiacapital', 'sofiacitycapital'];

    /** @var array<string, string>|null normalised oblast name => region code */
    private ?array $regionIndex = null;

    /**
     * How many of a courier's settlements the build would actually use: a numeric id of 1 to 18 digits, once each. The same filter
     * the join applies, so the circuit breaker counts what the builder would place.
     *
     * @param  list<CourierSettlement>  $settlements
     */
    public static function usableCount(array $settlements): int
    {
        $seen = [];

        foreach ($settlements as $settlement) {
            if (preg_match('/^\d{1,18}$/D', $settlement->reference) === 1) {
                $seen[$settlement->reference] = true;
            }
        }

        return count($seen);
    }

    /**
     * @param  array<string, list<CourierSettlement>>  $lists  carrier code => that courier's settlements; [] builds only the backbone
     */
    public function build(array $lists): SettlementBuildResult
    {
        $result = new SettlementBuildResult;
        $rows = $this->load();
        $original = $rows;

        $this->backbone($rows);
        $result->backbone = count(array_filter($rows, static fn (array $r): bool => $r['source'] === 'postcodes' && $r['part'] === ''));

        foreach ($lists as $carrier => $settlements) {
            if (isset(self::COLUMNS[$carrier])) {
                $this->join($rows, $carrier, $settlements, $result);
            }
        }

        $this->persist($rows, $original, array_keys(array_intersect_key(self::COLUMNS, $lists)), $result);

        return $result;
    }

    /** @return array<string, array<string, mixed>> keyed by identity */
    private function load(): array
    {
        $rows = [];

        foreach (DB::table('shipping_settlements')->where('country_code', self::COUNTRY)->orderBy('id')->get() as $row) {
            $rows[$this->key($row->region_code, $row->name_key, $row->part)] = [
                'id' => (int) $row->id,
                'name' => $row->name,
                'name_key' => $row->name_key,
                'region_code' => $row->region_code,
                'part' => $row->part,
                'postcodes' => json_decode((string) $row->postcodes, true) ?: [],
                'speedy_site_id' => $row->speedy_site_id === null ? null : (int) $row->speedy_site_id,
                'econt_city_id' => $row->econt_city_id === null ? null : (int) $row->econt_city_id,
                'source' => $row->source,
                'is_split' => (bool) $row->is_split,
            ];
        }

        return $rows;
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function backbone(array &$rows): void
    {
        $groups = [];

        foreach (BgPostcodes::entries() as $postcode => $pairs) {
            foreach ($pairs as [$name, $region]) {
                $nameKey = BgPostcodes::normalise($name);

                if ($nameKey === '') {
                    continue;
                }

                $g = &$groups[$region."\0".$nameKey];
                $g['name'] ??= $name;
                $g['region'] = $region;
                $g['name_key'] = $nameKey;
                $g['postcodes'][(string) $postcode] = (string) $postcode;
                unset($g);
            }
        }

        // The postcodes a split part already owns are not the base row's any more.
        $claimed = [];

        foreach ($rows as $row) {
            if ($row['source'] === 'postcodes' && $row['part'] !== '') {
                foreach ($row['postcodes'] as $postcode) {
                    $claimed[$row['region_code']."\0".$row['name_key']][$postcode] = true;
                }
            }
        }

        foreach ($groups as $groupKey => $g) {
            $postcodes = array_values(array_diff(array_values($g['postcodes']), array_keys($claimed[$groupKey] ?? [])));
            sort($postcodes, SORT_STRING);
            $key = $this->key($g['region'], $g['name_key'], '');

            if ($postcodes === [] && ! isset($rows[$key])) {
                continue;
            }

            $rows[$key] = ($rows[$key] ?? $this->blank($g['region'], $g['name_key'], '')) + [];
            $rows[$key]['name'] = mb_substr($g['name'], 0, 120);
            $rows[$key]['postcodes'] = $postcodes;
            $rows[$key]['source'] = 'postcodes';
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<CourierSettlement>  $settlements
     */
    private function join(array &$rows, string $carrier, array $settlements, SettlementBuildResult $result): void
    {
        $column = self::COLUMNS[$carrier];

        foreach ($rows as &$row) {
            $row[$column] = null;
        }
        unset($row);

        // Backbone rows (and their split parts) by normalised name.
        $byName = [];

        foreach ($rows as $key => $row) {
            if ($row['source'] === 'postcodes') {
                $byName[$row['name_key']][] = $key;
            }
        }

        /** @var array<string, list<CourierSettlement>> $matches */
        $matches = [];
        $seen = [];

        foreach ($settlements as $settlement) {
            $ref = $settlement->reference;

            if (preg_match('/^\d{1,18}$/D', $ref) !== 1 || isset($seen[$ref])) {
                $result->skipped[$carrier] = ($result->skipped[$carrier] ?? 0) + 1;

                continue;
            }

            $seen[$ref] = true;
            $nameKey = BgPostcodes::normalise($settlement->name);
            $postcode = $this->postcode($settlement->postcode);
            $regions = $this->regionCodes($settlement->regionName);
            $candidates = $nameKey === '' ? [] : ($byName[$nameKey] ?? []);

            $viaPostcode = array_values(array_filter($candidates, static fn (string $k): bool => $postcode !== null && in_array($postcode, $rows[$k]['postcodes'], true)));

            if (count($viaPostcode) === 1) {
                $matches[$viaPostcode[0]][] = $settlement;
                $result->byPostcode[$carrier] = ($result->byPostcode[$carrier] ?? 0) + 1;

                continue;
            }

            $pool = count($viaPostcode) > 1 ? $viaPostcode : $candidates;
            $regionsInPool = array_values(array_unique(array_map(static fn (string $k): string => $rows[$k]['region_code'], array_filter($pool, static fn (string $k): bool => in_array($rows[$k]['region_code'], $regions, true)))));

            if (count($regionsInPool) === 1) {
                $inRegion = array_values(array_filter($pool, static fn (string $k): bool => $rows[$k]['region_code'] === $regionsInPool[0]));
                $target = in_array($this->key($regionsInPool[0], $nameKey, ''), $inRegion, true) ? $this->key($regionsInPool[0], $nameKey, '') : $inRegion[0];
                $matches[$target][] = $settlement;
                $result->byRegion[$carrier] = ($result->byRegion[$carrier] ?? 0) + 1;

                continue;
            }

            // (c) no match: a row of its own, flagged by its source.
            $part = self::PREFIX[$carrier].$ref;
            $region = count($regions) === 1 ? $regions[0] : '';
            $key = $this->key($region, $nameKey !== '' ? $nameKey : 'ref'.$ref, $part);
            $rows[$key] = ($rows[$key] ?? $this->blank($region, $nameKey !== '' ? $nameKey : 'ref'.$ref, $part));
            $rows[$key]['name'] = mb_substr($settlement->name, 0, 120);
            $rows[$key]['postcodes'] = $postcode === null ? [] : [$postcode];
            $rows[$key]['source'] = 'courier';
            $rows[$key][$column] = (int) $ref;
            $result->unmatched[$carrier] = ($result->unmatched[$carrier] ?? 0) + 1;
        }

        foreach ($matches as $key => $list) {
            if (count($list) === 1) {
                $rows[$key][$column] = (int) $list[0]->reference;

                continue;
            }

            $this->split($rows, $key, $column, $list);
            $result->splits[$carrier] = ($result->splits[$carrier] ?? 0) + 1;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<CourierSettlement>  $list  two or more settlements of one courier that matched the same row
     */
    private function split(array &$rows, string $key, string $column, array $list): void
    {
        $base = $rows[$key];
        $postcodes = array_map(fn (CourierSettlement $s): ?string => $this->postcode($s->postcode), $list);
        $counts = array_count_values(array_filter($postcodes, static fn (?string $p): bool => $p !== null));

        $rows[$key][$column] = null;
        $rows[$key]['is_split'] = true;

        foreach ($list as $i => $settlement) {
            $postcode = $postcodes[$i];
            $byPostcode = $postcode !== null && ($counts[$postcode] ?? 0) === 1 && in_array($postcode, $base['postcodes'], true);
            $part = $byPostcode ? $postcode : 'x'.$settlement->reference;
            $partKey = $this->key($base['region_code'], $base['name_key'], $part);

            $rows[$partKey] = $rows[$partKey] ?? $this->blank($base['region_code'], $base['name_key'], $part);
            $rows[$partKey]['name'] = $base['name'];
            $rows[$partKey]['postcodes'] = $byPostcode ? [$postcode] : [];
            $rows[$partKey]['source'] = 'postcodes';
            $rows[$partKey]['is_split'] = true;
            $rows[$partKey][$column] = (int) $settlement->reference;

            if ($byPostcode) {
                $rows[$key]['postcodes'] = array_values(array_diff($rows[$key]['postcodes'], [$postcode]));
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $original
     * @param  list<string>  $carriers  whose id columns were rebuilt
     */
    private function persist(array $rows, array $original, array $carriers, SettlementBuildResult $result): void
    {
        $changed = [];
        $columns = array_map(static fn (string $c): string => self::COLUMNS[$c], $carriers);

        foreach ($rows as $key => $row) {
            $old = $original[$key] ?? null;

            if ($old === null) {
                $changed[$key] = $row;
                $result->created++;
            } elseif ($this->differs($old, $row)) {
                $changed[$key] = $row;
                $result->updated++;
            }
        }

        if ($changed === []) {
            return;
        }

        DB::transaction(function () use ($changed, $original, $columns): void {
            // The id columns are UNIQUE: free every id that moves before it is written again.
            foreach ($columns as $column) {
                $moving = [];

                foreach ($original as $key => $old) {
                    if ($old[$column] !== null && isset($changed[$key]) && $changed[$key][$column] !== $old[$column]) {
                        $moving[] = $old['id'];
                    }
                }

                foreach (array_chunk($moving, 500) as $ids) {
                    DB::table('shipping_settlements')->whereIn('id', $ids)->update([$column => null]);
                }
            }

            foreach (array_chunk(array_values($changed), 500) as $chunk) {
                DB::table('shipping_settlements')->upsert(
                    array_map(fn (array $r): array => [
                        'country_code' => self::COUNTRY,
                        'name' => $r['name'],
                        'name_key' => $r['name_key'],
                        'region_code' => $r['region_code'],
                        'part' => $r['part'],
                        'postcodes' => json_encode(array_values($r['postcodes'])),
                        'speedy_site_id' => $r['speedy_site_id'],
                        'econt_city_id' => $r['econt_city_id'],
                        'source' => $r['source'],
                        'is_split' => $r['is_split'] ? 1 : 0,
                    ], $chunk),
                    ['country_code', 'region_code', 'name_key', 'part'],
                    ['name', 'postcodes', 'speedy_site_id', 'econt_city_id', 'source', 'is_split'],
                );
            }
        });
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function differs(array $a, array $b): bool
    {
        foreach (['name', 'postcodes', 'speedy_site_id', 'econt_city_id', 'source', 'is_split'] as $field) {
            if ($a[$field] !== $b[$field]) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function blank(string $region, string $nameKey, string $part): array
    {
        return [
            'id' => null, 'name' => $nameKey, 'name_key' => $nameKey, 'region_code' => $region, 'part' => $part, 'postcodes' => [],
            'speedy_site_id' => null, 'econt_city_id' => null, 'source' => 'postcodes', 'is_split' => false,
        ];
    }

    private function key(string $region, string $nameKey, string $part): string
    {
        return $region."\0".$nameKey."\0".$part;
    }

    private function postcode(?string $postcode): ?string
    {
        $postcode = $postcode === null ? null : trim($postcode);

        return BgPostcodes::isPostcode($postcode) ? $postcode : null;
    }

    /**
     * The oblast codes a courier's region NAME can mean: [] when unreadable, several when it is the ambiguous «София» (the city
     * or the province). Cyrillic and Latin spellings meet in BgPostcodes::normalise().
     *
     * @return list<string>
     */
    private function regionCodes(?string $name): array
    {
        if ($name === null || trim($name) === '') {
            return [];
        }

        $key = BgPostcodes::normaliseRegion($name);

        if (in_array($key, self::SOFIA_CITY, true)) {
            return ['SOF'];
        }

        if ($this->regionIndex === null) {
            $this->regionIndex = [];

            foreach (BgRegions::NAMES as $code => $regionName) {
                $this->regionIndex[$code] = BgPostcodes::normaliseRegion($regionName);
            }
        }

        $codes = array_keys(array_filter($this->regionIndex, static fn (string $k): bool => $k === $key));

        // «София» alone is the province by its own name, but a courier means the city by it as often: both stay possible.
        if ($key === 'sofia') {
            $codes = ['SFO', 'SOF'];
        }

        return array_values($codes);
    }
}
