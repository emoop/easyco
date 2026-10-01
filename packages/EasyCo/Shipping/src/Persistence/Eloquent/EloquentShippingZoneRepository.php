<?php

namespace EasyCo\Shipping\Persistence\Eloquent;

use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\ShippingZone;

/**
 * Maps the ShippingZone entity onto `shipping_zones`. The JSON columns are
 * encoded here with JSON_UNESCAPED_UNICODE (see ShippingZoneModel) and decoded
 * back into plain string lists.
 */
final class EloquentShippingZoneRepository implements ShippingZoneRepository
{
    public function save(ShippingZone $zone): void
    {
        $model = $zone->id() !== null
            ? ShippingZoneModel::findOrFail($zone->id())
            : new ShippingZoneModel();

        $model->name = $zone->name();
        $model->sort_order = $zone->sortOrder();
        $model->country_codes = json_encode($zone->countryCodes(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $model->settlement_patterns = $zone->settlementPatterns() === null
            ? null
            : json_encode($zone->settlementPatterns(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $model->save();

        if ($zone->id() === null) {
            $zone->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?ShippingZone
    {
        $model = ShippingZoneModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return ShippingZone[] */
    public function allOrdered(): array
    {
        return ShippingZoneModel::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ShippingZoneModel $model) => $this->toDomain($model))
            ->all();
    }

    private function toDomain(ShippingZoneModel $model): ShippingZone
    {
        $patterns = $model->settlement_patterns === null
            ? null
            : array_map('strval', json_decode((string) $model->settlement_patterns, true, flags: JSON_THROW_ON_ERROR));

        return ShippingZone::reconstituteFromStorage(
            id: (string) $model->id,
            name: $model->name,
            sortOrder: (int) $model->sort_order,
            countryCodes: array_map('strval', json_decode((string) $model->country_codes, true, flags: JSON_THROW_ON_ERROR)),
            settlementPatterns: $patterns,
        );
    }
}
