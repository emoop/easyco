<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Enums\MeasureUnit;
use EasyCo\Catalog\Exceptions\MeasurementRefusedException;
use EasyCo\Catalog\Measure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Maps Measure onto catalog_measures. A duplicate name is detected by SQLSTATE 23000 plus the driver code (MySQL 1062 / SQLite 19) and the constraint name — never by the message text alone
 * (CLAUDE.md rule 3). The unit lock and the delete rule are checked here because only the database knows the stored numbers.
 */
final class EloquentMeasureRepository implements MeasureRepository
{
    public function save(Measure $measure): void
    {
        $model = $measure->id() !== null
            ? MeasureModel::findOrFail($measure->id())
            : new MeasureModel();

        if ($model->exists && $model->unit !== $measure->unit()->value) {
            $values = $this->valueCount((string) $model->id);

            if ($values > 0) {
                throw MeasurementRefusedException::unitLocked((string) $model->id, $values);
            }
        }

        $model->name = $measure->name();
        $model->unit = $measure->unit()->value;
        $model->sort = $measure->sort();
        $model->is_active = $measure->isActive();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isNameUniqueViolation($e)) {
                throw MeasurementRefusedException::nameTaken('measure', $measure->name());
            }

            throw $e;
        }

        if ($measure->id() === null) {
            $measure->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Measure
    {
        $model = MeasureModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return Measure[] */
    public function all(): array
    {
        return MeasureModel::query()->orderBy('sort')->orderBy('id')->get()
            ->map(fn (MeasureModel $model) => $this->toDomain($model))
            ->all();
    }

    /** @return Measure[] */
    public function active(): array
    {
        return MeasureModel::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (MeasureModel $model) => $this->toDomain($model))
            ->all();
    }

    public function usage(string $id): array
    {
        return [
            'templates' => DB::table('catalog_measurement_template_measures')->where('measure_id', $id)->count(),
            'values' => $this->valueCount($id),
        ];
    }

    public function delete(string $id): void
    {
        $usage = $this->usage($id);

        if ($usage['templates'] > 0 || $usage['values'] > 0) {
            throw MeasurementRefusedException::measureInUse($id, $usage['templates'], $usage['values']);
        }

        MeasureModel::query()->whereKey($id)->delete();
    }

    private function valueCount(string $measureId): int
    {
        return DB::table('catalog_measurement_values')->where('measure_id', $measureId)->count();
    }

    private function isNameUniqueViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || ! in_array($driverErrorCode, [1062, 19], true)) {
            return false;
        }

        $message = (string) ($errorInfo[2] ?? '');

        return str_contains($message, 'cm_name_unique') || str_contains($message, 'catalog_measures.name');
    }

    private function toDomain(MeasureModel $model): Measure
    {
        return new Measure(
            id: (string) $model->id,
            name: $model->name,
            // a stored unit outside the closed list (a hand edit) reads as the default so a list never breaks
            unit: MeasureUnit::tryFrom((string) $model->unit) ?? MeasureUnit::CM,
            sort: (int) $model->sort,
            active: (bool) $model->is_active,
        );
    }
}
