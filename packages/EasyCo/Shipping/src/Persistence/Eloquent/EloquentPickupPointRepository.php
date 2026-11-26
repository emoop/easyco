<?php

namespace EasyCo\Shipping\Persistence\Eloquent;

use DateTimeImmutable;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Contracts\PickupPointRepository;
use Illuminate\Support\Facades\DB;

/**
 * The query-builder implementation of PickupPointRepository (no Eloquent model: the sync writes thousands of rows in
 * chunks and never loads one as an object). Unique `(carrier_code, external_ref)` is the last net: a chunk is an
 * insert-or-update on that key, so two runs racing cannot create a second row for one courier point.
 *
 * `settlement_id` is never written here (COUR-1b maps points to settlements); a re-sync leaves whatever 1b set.
 */
final class EloquentPickupPointRepository implements PickupPointRepository
{
    private const TABLE = 'shipping_pickup_points';

    /** Columns whose change makes a row "changed" (and is rewritten); `features` is compared as decoded JSON. */
    private const COMPARED = ['external_id', 'kind', 'courier_type', 'name', 'settlement_name', 'postcode', 'address_line', 'latitude', 'longitude'];

    public function activeCount(string $carrierCode): int
    {
        return DB::table(self::TABLE)->where('carrier_code', $carrierCode)->where('is_active', true)->count();
    }

    public function upsertChunk(string $carrierCode, array $points, DateTimeImmutable $runStartedAt): array
    {
        $result = ['new' => 0, 'changed' => 0, 'unchanged' => 0];

        if ($points === []) {
            return $result;
        }

        $stamp = $runStartedAt->format('Y-m-d H:i:s.u');

        DB::transaction(function () use ($carrierCode, $points, $stamp, &$result): void {
            $existing = DB::table(self::TABLE)
                ->where('carrier_code', $carrierCode)
                ->whereIn('external_ref', array_map(static fn (PickupPoint $p): string => $p->reference, $points))
                ->get()
                ->keyBy('external_ref');

            $write = [];
            $unchanged = [];

            foreach ($points as $point) {
                $row = $this->row($point);
                $old = $existing->get($point->reference);

                if ($old === null) {
                    $result['new']++;
                    $write[] = $row + ['is_active' => true, 'first_seen_at' => $stamp, 'synced_at' => $stamp, 'deactivated_at' => null];

                    continue;
                }

                if ($this->differs($old, $row)) {
                    $result['changed']++;
                    $write[] = $row + ['is_active' => true, 'first_seen_at' => $old->first_seen_at, 'synced_at' => $stamp, 'deactivated_at' => null];

                    continue;
                }

                $result['unchanged']++;
                $unchanged[] = $point->reference;
            }

            if ($write !== []) {
                DB::table(self::TABLE)->upsert(
                    $write,
                    ['carrier_code', 'external_ref'],
                    array_merge(array_diff(array_keys($this->row($points[0])), ['carrier_code', 'external_ref']), ['is_active', 'synced_at', 'deactivated_at']),
                );
            }

            if ($unchanged !== []) {
                DB::table(self::TABLE)->where('carrier_code', $carrierCode)->whereIn('external_ref', $unchanged)->update(['synced_at' => $stamp]);
            }
        });

        return $result;
    }

    public function deactivateUnseen(string $carrierCode, DateTimeImmutable $runStartedAt): int
    {
        $stamp = $runStartedAt->format('Y-m-d H:i:s.u');

        return DB::table(self::TABLE)
            ->where('carrier_code', $carrierCode)
            ->where('is_active', true)
            ->where('synced_at', '<', $stamp)
            ->update(['is_active' => false, 'deactivated_at' => $stamp]);
    }

    /** @return array<string, mixed> */
    private function row(PickupPoint $point): array
    {
        return [
            'carrier_code' => $point->carrierCode,
            'external_ref' => $point->reference,
            'external_id' => $point->externalId,
            'kind' => $point->kind->value,
            'courier_type' => $point->courierType,
            'name' => $point->name,
            'settlement_name' => $point->settlement,
            'postcode' => $point->postcode,
            'address_line' => $point->addressLine,
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'features' => $point->features === [] ? null : json_encode($point->features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @param array<string, mixed> $row */
    private function differs(object $old, array $row): bool
    {
        if (! (bool) $old->is_active) {
            return true;
        }

        foreach (self::COMPARED as $column) {
            $a = $old->{$column};
            $b = $row[$column];

            if (in_array($column, ['latitude', 'longitude'], true)) {
                if (($a === null) !== ($b === null) || ($a !== null && abs((float) $a - (float) $b) > 0.0000005)) {
                    return true;
                }

                continue;
            }

            if ((string) $a !== (string) $b || ($a === null) !== ($b === null)) {
                return true;
            }
        }

        $oldFeatures = $old->features === null ? null : json_decode((string) $old->features, true);
        $newFeatures = $row['features'] === null ? null : json_decode((string) $row['features'], true);

        return $oldFeatures !== $newFeatures;
    }
}
