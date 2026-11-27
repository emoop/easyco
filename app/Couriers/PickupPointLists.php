<?php

namespace App\Couriers;

use App\Support\BgPostcodes;
use App\Support\BgRegions;
use Illuminate\Support\Facades\DB;

/**
 * What the two public read endpoints answer (COUR-2a, design §4.3): the settlements in which a courier has an offerable point, and
 * those points. Local tables only; "offerable" is OfferablePickupPoints' definition and nowhere else.
 *
 * THE SETTLEMENT KEY the search returns and the points call takes:
 *   `<digits>`        a row of `shipping_settlements` (its id);
 *   `n:<name key>`    points that have NO settlement row (BOX NOW has a few dozen: GeoNames gaps and shared names): the key is
 *                     BgPostcodes::normalise() of the name as the courier wrote it, and the item shows that name, no oblast.
 * The two shapes cannot collide: an id is digits only, the other starts with `n:`.
 */
final class PickupPointLists
{
    public const MAX_SETTLEMENTS = 20;

    /** A settlement with hundreds of points is still one list; the ceiling only stops a runaway table. */
    public const MAX_POINTS = 500;

    public function __construct(private readonly OfferablePickupPoints $offerable) {}

    /**
     * @return list<array{id: string, name: string, region: ?string, postcode: ?string, is_split: bool, points: int}>|null null = the courier is not on, or the kind is unknown
     */
    public function settlements(?string $carrier, string $kind, string $q): ?array
    {
        // COUR-2a.4: `address` = every settlement (the place of a street address), no courier, no points
        if ($kind === 'address') {
            return $this->allSettlements($q);
        }

        $base = $carrier === null ? null : $this->offerable->query($carrier, $kind);

        if ($base === null) {
            return null;
        }

        $digits = preg_match('/^\d{2,12}$/D', $q) === 1;
        $key = $digits ? '' : BgPostcodes::normalise($q);

        if (! $digits && $key === '') {
            return [];
        }

        $items = [];

        $mapped = (clone $base)
            ->join('shipping_settlements as s', 's.id', '=', 'p.settlement_id')
            ->when($digits, static fn ($query) => $query->where('s.postcodes', 'like', '%"'.$q.'%'), static fn ($query) => $query->where('s.name_key', 'like', $key.'%'))
            ->groupBy('s.id', 's.name', 's.name_key', 's.region_code', 's.postcodes', 's.is_split')
            ->select('s.id', 's.name', 's.name_key', 's.region_code', 's.postcodes', 's.is_split', DB::raw('COUNT(p.id) as points'))
            ->get();

        foreach ($mapped as $row) {
            $postcodes = json_decode((string) $row->postcodes, true);
            $region = (string) $row->region_code === '' ? null : BgRegions::name((string) $row->region_code);

            $items[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'region' => $region,
                'postcode' => is_array($postcodes) && $postcodes !== [] ? (string) reset($postcodes) : null,
                'is_split' => (bool) $row->is_split,
                'type' => null,
                'points' => (int) $row->points,
                '_key' => (string) $row->name_key,
            ];
        }

        // Points with no settlement row are grouped by the name the courier wrote; the names are few, so the prefix test is done here
        // on the normalised form (the table holds no normalised copy). A postcode search never reaches them (they have none).
        $names = $digits ? [] : (clone $base)->whereNull('p.settlement_id')->groupBy('p.settlement_name')->select('p.settlement_name', DB::raw('COUNT(p.id) as points'))->get();
        $byKey = [];

        foreach ($names as $row) {
            $nameKey = BgPostcodes::normalise((string) $row->settlement_name);

            if ($nameKey === '' || ! str_starts_with($nameKey, $key)) {
                continue;
            }

            $byKey[$nameKey] ??= ['name' => (string) $row->settlement_name, 'points' => 0];
            $byKey[$nameKey]['points'] += (int) $row->points;
        }

        foreach ($byKey as $nameKey => $group) {
            $items[] = ['id' => 'n:'.$nameKey, 'name' => $group['name'], 'region' => null, 'postcode' => null, 'is_split' => false, 'type' => null, 'points' => $group['points'], '_key' => (string) $nameKey];
        }

        // exact name first, then the most points, then the name
        usort($items, static fn (array $a, array $b): int => [$b['_key'] === $key, $b['points'], $a['name'], $a['id']] <=> [$a['_key'] === $key, $a['points'], $b['name'], $b['id']]);

        return array_map(static function (array $item): array {
            unset($item['_key']);

            return $item;
        }, array_slice($items, 0, self::MAX_SETTLEMENTS));
    }

    /**
     * COUR-2a.6: what the page needs to tell «your place is wrong» from «your place is right, this courier has no point of this kind there». Computed ONLY for an
     * empty answer (no extra query otherwise), additive (`data` keeps its shape).
     *   place_exists  the text IS a known settlement (its name, normalised exactly, or one of its postcodes), whatever the courier and the kind;
     *   other_kinds   the kinds this courier DOES offer in that place (only for a courier + kind search; omitted when there are none).
     *
     * @return array{place_exists: bool, other_kinds?: list<string>}
     */
    public function emptyMeta(?string $carrier, string $kind, string $q): array
    {
        $digits = preg_match('/^\d{2,12}$/D', $q) === 1;
        $key = $digits ? '' : BgPostcodes::normalise($q);

        if (! $digits && $key === '') {
            return ['place_exists' => false];
        }

        $ids = DB::table('shipping_settlements as s')
            ->when($digits, static fn ($query) => $query->where('s.postcodes', 'like', '%"'.$q.'"%'), static fn ($query) => $query->where('s.name_key', $key))
            ->limit(50)->pluck('s.id')->all();

        if ($ids === []) {
            return ['place_exists' => false];
        }

        $meta = ['place_exists' => true];

        if ($carrier !== null && $kind !== 'address') {
            $others = [];

            foreach (['office', 'locker'] as $other) {
                $query = $other === $kind || $kind === 'pickup' ? null : $this->offerable->query($carrier, $other);

                if ($query !== null && (clone $query)->whereIn('p.settlement_id', $ids)->exists()) {
                    $others[] = $other;
                }
            }

            if ($others !== []) {
                $meta['other_kinds'] = $others;
            }
        }

        return $meta;
    }

    /**
     * Every settlement (the place of a street address), by the start of its name or by postcode. `type` («гр.» / «с.») is not stored yet: null.
     *
     * @return list<array{id: string, name: string, region: ?string, postcode: ?string, is_split: bool, type: ?string, points: ?int}>
     */
    private function allSettlements(string $q): array
    {
        $digits = preg_match('/^\d{2,12}$/D', $q) === 1;
        $key = $digits ? '' : BgPostcodes::normalise($q);

        if (! $digits && $key === '') {
            return [];
        }

        $rows = DB::table('shipping_settlements as s')
            ->when($digits, static fn ($query) => $query->where('s.postcodes', 'like', '%"'.$q.'%'), static fn ($query) => $query->where('s.name_key', 'like', $key.'%'))
            ->select('s.id', 's.name', 's.name_key', 's.region_code', 's.postcodes', 's.is_split')
            ->orderByRaw('s.name_key = ? DESC', [$key])->orderBy('s.name')->orderBy('s.id')
            ->limit(self::MAX_SETTLEMENTS)
            ->get();

        return $rows->map(static function (object $row): array {
            $postcodes = json_decode((string) $row->postcodes, true);

            return [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'region' => (string) $row->region_code === '' ? null : BgRegions::name((string) $row->region_code),
                'postcode' => is_array($postcodes) && $postcodes !== [] ? (string) reset($postcodes) : null,
                'is_split' => (bool) $row->is_split,
                'type' => null,
                'points' => null,
            ];
        })->all();
    }

    /**
     * @return list<array{id: int, ref: string, kind: string, name: string, address: string, postcode: ?string, lat: ?float, lng: ?float}>|null
     */
    public function points(string $carrier, string $kind, string $settlement): ?array
    {
        $query = $this->offerable->query($carrier, $kind);

        if ($query === null) {
            return null;
        }

        if (preg_match('/^\d{1,18}$/D', $settlement) === 1) {
            $query->where('p.settlement_id', (int) $settlement);
        } elseif (preg_match('/^n:[a-z0-9]{1,120}$/D', $settlement) === 1) {
            $key = substr($settlement, 2);
            $names = (clone $query)->whereNull('p.settlement_id')->distinct()->pluck('p.settlement_name')
                ->filter(static fn ($name): bool => BgPostcodes::normalise((string) $name) === $key)->values()->all();

            if ($names === []) {
                return [];
            }

            $query->whereNull('p.settlement_id')->whereIn('p.settlement_name', $names);
        } else {
            return [];
        }

        return $query->orderBy('p.name')->orderBy('p.id')->limit(self::MAX_POINTS)
            ->select('p.id', 'p.external_ref', 'p.kind', 'p.name', 'p.address_line', 'p.postcode', 'p.latitude', 'p.longitude')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'ref' => (string) $row->external_ref,
                'kind' => (string) $row->kind,
                'name' => (string) $row->name,
                'address' => (string) $row->address_line,
                'postcode' => $row->postcode === null ? null : (string) $row->postcode,
                'lat' => $row->latitude === null ? null : (float) $row->latitude,
                'lng' => $row->longitude === null ? null : (float) $row->longitude,
            ])->all();
    }
}
