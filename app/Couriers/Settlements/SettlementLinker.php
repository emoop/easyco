<?php

namespace App\Couriers\Settlements;

use App\Support\BgPostcodes;
use Illuminate\Support\Facades\DB;

/**
 * Sets `shipping_pickup_points.settlement_id` after a points sync (courier-integration-design.md §3.3 items 4 and 5):
 *  - a courier that names a settlement for each point (Speedy `siteId`, Econt `city.id`): through ITS OWN settlement id, never
 *    through the address text. One UPDATE joined to the matching id column; a point whose id has no row gets NULL;
 *  - a courier that does not (BOX NOW): through postcode + name by the same rules (a) and (b) as the settlements themselves.
 * A point that maps to nothing keeps NULL: it stays selectable under its own `settlement_name`, and is COUNTED as unmapped.
 */
final class SettlementLinker
{
    /**
     * @return int how many ACTIVE points of the carrier have no settlement after the link
     */
    public function link(string $carrierCode): int
    {
        $column = SettlementBuilder::COLUMNS[$carrierCode] ?? null;

        if ($column !== null) {
            DB::update(
                "UPDATE shipping_pickup_points p LEFT JOIN shipping_settlements s ON s.{$column} = CAST(p.courier_settlement_ref AS UNSIGNED) SET p.settlement_id = s.id WHERE p.carrier_code = ?",
                [$carrierCode],
            );
        } else {
            $this->linkByPostcodeAndName($carrierCode);
        }

        return DB::table('shipping_pickup_points')->where('carrier_code', $carrierCode)->where('is_active', 1)->whereNull('settlement_id')->count();
    }

    private function linkByPostcodeAndName(string $carrierCode): void
    {
        $rows = DB::table('shipping_settlements')->where('country_code', SettlementBuilder::COUNTRY)->where('source', 'postcodes')->get(['id', 'name_key', 'region_code', 'part', 'postcodes']);
        $byName = [];

        foreach ($rows as $row) {
            $byName[$row->name_key][] = ['id' => (int) $row->id, 'region' => $row->region_code, 'part' => $row->part, 'postcodes' => json_decode((string) $row->postcodes, true) ?: []];
        }

        $pairs = DB::table('shipping_pickup_points')->where('carrier_code', $carrierCode)->select('postcode', 'settlement_name')->distinct()->get();
        $idsByPair = [];

        foreach ($pairs as $pair) {
            $idsByPair[] = [$pair->postcode, $pair->settlement_name, $this->match($byName, $pair->postcode, $pair->settlement_name)];
        }

        foreach ($idsByPair as [$postcode, $name, $id]) {
            DB::table('shipping_pickup_points')
                ->where('carrier_code', $carrierCode)
                ->where('settlement_name', $name)
                ->when($postcode === null, fn ($q) => $q->whereNull('postcode'), fn ($q) => $q->where('postcode', $postcode))
                ->update(['settlement_id' => $id]);
        }
    }

    /**
     * @param  array<string, list<array{id: int, region: string, part: string, postcodes: list<string>}>>  $byName
     */
    private function match(array $byName, ?string $postcode, string $name): ?int
    {
        $nameKey = BgPostcodes::normalise($name);
        $candidates = $nameKey === '' ? [] : ($byName[$nameKey] ?? []);
        $valid = BgPostcodes::isPostcode($postcode);

        // (a) the postcode is the row's and the names are equal
        $viaPostcode = $valid ? array_values(array_filter($candidates, static fn (array $c): bool => in_array(trim((string) $postcode), $c['postcodes'], true))) : [];

        if (count($viaPostcode) === 1) {
            return $viaPostcode[0]['id'];
        }

        // (b) the names are equal and the postcode names exactly one region for this settlement
        $regions = $valid ? BgPostcodes::regionCodesOfPostcodeAndName(trim((string) $postcode), $name) : [];
        $pool = count($viaPostcode) > 1 ? $viaPostcode : $candidates;
        // A postcode the data file does not know (most of Sofia's: the file holds only 1000) names no region. Then the name decides ONLY if it
        // is one settlement in the whole backbone, i.e. exactly one region has it: that is still name + region, with nothing to confuse it with.
        $inRegion = $regions === []
            ? $pool
            : array_values(array_filter($pool, static fn (array $c): bool => in_array($c['region'], $regions, true)));
        $distinct = array_values(array_unique(array_column($inRegion, 'region')));

        if (count($distinct) !== 1) {
            return null;
        }

        foreach ($inRegion as $c) {
            if ($c['part'] === '') {
                return $c['id'];
            }
        }

        return $inRegion[0]['id'];
    }
}
