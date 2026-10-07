<?php

namespace EasyCo\Shipping\Persistence\Eloquent;

use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Exceptions\UnknownShippingClassException;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Maps the ShippingMethod entity onto `shipping_methods` and
 * `shipping_method_class_rates` as ONE unit: saving a method writes the row and
 * REPLACES its rates inside a single database transaction (no external call is
 * made inside it). A rate for a class code that does not exist is refused with
 * UnknownShippingClassException — checked up front so the common case never
 * reaches SQL, and again if the foreign key itself refuses the row (a class
 * removed between the check and the insert), detected by SQLSTATE 23000 plus
 * the driver error code (MySQL 1452 / SQLite 19), never by message text alone.
 */
final class EloquentShippingMethodRepository implements ShippingMethodRepository
{
    public function save(ShippingMethod $method): void
    {
        $rates = $method->classRates();

        if ($rates !== []) {
            // PHP turns a numeric-looking array key ("2024") into an int, and
            // assigning it back into an array would just turn it into an int
            // again — so the codes are cast to strings wherever they are
            // compared against the database, not stored in a re-keyed array.
            $codes = array_map('strval', array_keys($rates));
            $known = DB::table('shipping_classes')->whereIn('code', $codes)->pluck('code')->all();
            $unknown = array_values(array_diff($codes, array_map('strval', $known)));

            if ($unknown !== []) {
                throw UnknownShippingClassException::forCodes($unknown);
            }
        }

        try {
            DB::transaction(function () use ($method, $rates): void {
                $model = $method->id() !== null
                    ? ShippingMethodModel::findOrFail($method->id())
                    : new ShippingMethodModel();

                $model->zone_id = $method->zoneId();
                $model->name = $method->name();
                $model->kind = $method->kind()->value;
                $model->sort_order = $method->sortOrder();
                $model->is_active = $method->isActive();
                $model->amount_minor = $method->amountMinor();
                $model->free_above_minor = $method->freeAboveMinor();
                $model->carrier_code = $method->carrierCode();
                $model->requires_pickup_point = $method->requiresPickupPoint();
                $model->save();

                DB::table('shipping_method_class_rates')->where('method_id', $model->id)->delete();

                if ($rates !== []) {
                    $rows = [];
                    foreach ($rates as $classCode => $amount) {
                        $rows[] = ['method_id' => $model->id, 'class_code' => (string) $classCode, 'amount_minor' => $amount];
                    }

                    DB::table('shipping_method_class_rates')->insert($rows);
                }

                if ($method->id() === null) {
                    $method->assignId((string) $model->id);
                }
            });
        } catch (QueryException $e) {
            if ($this->isClassForeignKeyViolation($e)) {
                throw UnknownShippingClassException::forCodes(array_map('strval', array_keys($rates)));
            }

            throw $e;
        }
    }

    public function findById(string $id): ?ShippingMethod
    {
        $model = ShippingMethodModel::find($id);

        if ($model === null) {
            return null;
        }

        return $this->toDomain($model, $this->ratesFor([(int) $model->id])[(int) $model->id] ?? []);
    }

    /** @return ShippingMethod[] */
    public function forZone(string $zoneId, bool $activeOnly = false): array
    {
        $query = ShippingMethodModel::query()->where('zone_id', $zoneId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $models = $query->orderBy('sort_order')->orderBy('id')->get();
        $rates = $this->ratesFor($models->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $models
            ->map(fn (ShippingMethodModel $model) => $this->toDomain($model, $rates[(int) $model->id] ?? []))
            ->all();
    }

    /**
     * @param  list<string|int>  $zoneIds
     * @return array<int, ShippingMethod[]>  zone id (int) => its methods, sortOrder ASC then id ASC
     */
    public function forZones(array $zoneIds, bool $activeOnly = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $zoneIds)));

        if ($ids === []) {
            return [];
        }

        // Query 1: the methods of every zone, in one read.
        $query = ShippingMethodModel::query()->whereIn('zone_id', $ids);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $models = $query->orderBy('sort_order')->orderBy('id')->get();

        // Query 2: every method's rates, in one read (none when there are no methods).
        $rates = $this->ratesFor($models->pluck('id')->map(fn ($id) => (int) $id)->all());

        $grouped = [];

        foreach ($models as $model) {
            $grouped[(int) $model->zone_id][] = $this->toDomain($model, $rates[(int) $model->id] ?? []);
        }

        return $grouped;
    }

    /**
     * Every given method's rates in ONE query.
     *
     * @param  list<int>  $methodIds
     * @return array<int, array<string, int>>
     */
    private function ratesFor(array $methodIds): array
    {
        if ($methodIds === []) {
            return [];
        }

        $grouped = [];

        foreach (DB::table('shipping_method_class_rates')->whereIn('method_id', $methodIds)->get() as $row) {
            $grouped[(int) $row->method_id][(string) $row->class_code] = (int) $row->amount_minor;
        }

        return $grouped;
    }

    /** @param array<string, int> $rates */
    private function toDomain(ShippingMethodModel $model, array $rates): ShippingMethod
    {
        return ShippingMethod::reconstituteFromStorage(
            id: (string) $model->id,
            zoneId: (string) $model->zone_id,
            name: $model->name,
            kind: ShippingMethodKind::from($model->kind),
            sortOrder: (int) $model->sort_order,
            isActive: (bool) $model->is_active,
            amountMinor: $model->amount_minor,
            classRates: $rates,
            freeAboveMinor: $model->free_above_minor,
            carrierCode: $model->carrier_code,
            requiresPickupPoint: (bool) $model->requires_pickup_point,
        );
    }

    private function isClassForeignKeyViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || ! in_array($driverErrorCode, [1452, 19], true)) {
            return false;
        }

        // ONLY this constraint's name: MySQL prints "FOREIGN KEY" for the zone
        // foreign key too, and a zone failure must surface as the original
        // QueryException, not as an unknown-class error.
        return str_contains((string) ($errorInfo[2] ?? ''), 'ship_class_rates_class_code_foreign');
    }
}
