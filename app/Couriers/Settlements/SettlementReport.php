<?php

namespace App\Couriers\Settlements;

use Illuminate\Support\Facades\DB;

/**
 * The short «unmapped / split settlements» report of the «Куриери» page (courier-integration-design.md §3.3, §5): COUNTS and at most
 * a handful of names, never a table. Facts only, derived from the tables on every read.
 */
final class SettlementReport
{
    public const SAMPLE = 8;

    /**
     * @return array{mapped: int, unmatched: int, unmatched_names: list<string>, splits: int, split_names: list<string>, points_unmapped: int, points_total: int}
     */
    public function for(string $carrierCode): array
    {
        $column = SettlementBuilder::COLUMNS[$carrierCode] ?? null;
        $points = DB::table('shipping_pickup_points')->where('carrier_code', $carrierCode)->where('is_active', 1);

        $report = [
            'mapped' => 0,
            'unmatched' => 0,
            'unmatched_names' => [],
            'splits' => 0,
            'split_names' => [],
            'points_total' => (clone $points)->count(),
            'points_unmapped' => (clone $points)->whereNull('settlement_id')->count(),
        ];

        if ($column === null) {
            return $report;
        }

        $own = DB::table('shipping_settlements')->whereNotNull($column);
        $report['mapped'] = (clone $own)->where('source', 'postcodes')->count();
        $report['unmatched'] = (clone $own)->where('source', 'courier')->count();
        $report['unmatched_names'] = (clone $own)->where('source', 'courier')->orderBy('name')->limit(self::SAMPLE)->pluck('name')->all();
        $report['splits'] = (clone $own)->where('is_split', 1)->where('part', '<>', '')->count();
        $report['split_names'] = (clone $own)->where('is_split', 1)->where('part', '<>', '')->orderBy('name')->limit(self::SAMPLE)->pluck('name')->unique()->values()->all();

        return $report;
    }
}
