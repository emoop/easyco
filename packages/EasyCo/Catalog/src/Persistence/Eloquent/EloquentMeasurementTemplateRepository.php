<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Enums\MeasurementTemplateKind;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\Exceptions\MeasurementRefusedException;
use EasyCo\Catalog\MeasurementTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Maps MeasurementTemplate onto catalog_measurement_templates plus its ordered list in catalog_measurement_template_measures (the position is the `sort` column). save() is one transaction: the row,
 * then the list replaced. A duplicate name is detected by SQLSTATE 23000 + driver code (CLAUDE.md rule 3).
 */
final class EloquentMeasurementTemplateRepository implements MeasurementTemplateRepository
{
    public function save(MeasurementTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $model = $template->id() !== null
                ? MeasurementTemplateModel::findOrFail($template->id())
                : new MeasurementTemplateModel();

            $model->name = $template->name();
            $model->sort = $template->sort();
            $model->is_active = $template->isActive();

            // the kind is locked once any product that uses this template holds a number (the rows would change meaning under the numbers)
            if ($template->id() !== null && $model->kind !== $template->kind()->value) {
                $holding = DB::table('catalog_product_measurement_templates as pt')
                    ->where('pt.template_id', $model->id)
                    ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('catalog_measurement_values as v')->whereColumn('v.product_id', 'pt.product_id'))
                    ->count();

                if ($holding > 0) {
                    throw MeasurementRefusedException::kindLocked((string) $model->id, $holding);
                }
            }

            $model->kind = $template->kind()->value;

            try {
                $model->save();
            } catch (QueryException $e) {
                if ($this->isNameUniqueViolation($e)) {
                    throw MeasurementRefusedException::nameTaken('template', $template->name());
                }

                throw $e;
            }

            $ids = $template->measureIds();

            if ($ids !== []) {
                $known = DB::table('catalog_measures')->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->all();

                foreach ($ids as $id) {
                    if (! in_array($id, $known, true)) {
                        throw InvalidMeasurementValueException::unknownReference('measure', $id);
                    }
                }
            }

            DB::table('catalog_measurement_template_measures')->where('template_id', $model->id)->delete();

            if ($ids !== []) {
                DB::table('catalog_measurement_template_measures')->insert(
                    array_map(
                        fn (string $measureId, int $position) => ['template_id' => $model->id, 'measure_id' => $measureId, 'sort' => $position],
                        $ids,
                        array_keys($ids),
                    )
                );
            }

            if ($template->id() === null) {
                $template->assignId((string) $model->id);
            }
        });
    }

    public function findById(string $id): ?MeasurementTemplate
    {
        $model = MeasurementTemplateModel::find($id);

        if ($model === null) {
            return null;
        }

        $ids = DB::table('catalog_measurement_template_measures')
            ->where('template_id', $id)
            ->orderBy('sort')
            ->pluck('measure_id')
            ->map(fn ($measureId) => (string) $measureId)
            ->all();

        return $this->toDomain($model, $ids);
    }

    /** @return MeasurementTemplate[] */
    public function all(): array
    {
        $models = MeasurementTemplateModel::query()->orderBy('sort')->orderBy('id')->get();

        if ($models->isEmpty()) {
            return [];
        }

        $byTemplate = [];
        foreach (DB::table('catalog_measurement_template_measures')->orderBy('sort')->get(['template_id', 'measure_id']) as $row) {
            $byTemplate[(string) $row->template_id][] = (string) $row->measure_id;
        }

        return $models
            ->map(fn (MeasurementTemplateModel $model) => $this->toDomain($model, $byTemplate[(string) $model->id] ?? []))
            ->all();
    }

    public function countProductsUsing(string $id): int
    {
        return DB::table('catalog_product_measurement_templates')->where('template_id', $id)->count();
    }

    public function delete(string $id): void
    {
        $products = $this->countProductsUsing($id);

        if ($products > 0) {
            throw MeasurementRefusedException::templateInUse($id, $products);
        }

        MeasurementTemplateModel::query()->whereKey($id)->delete();
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

        return str_contains($message, 'cmt_name_unique') || str_contains($message, 'catalog_measurement_templates.name');
    }

    /** @param string[] $measureIds */
    private function toDomain(MeasurementTemplateModel $model, array $measureIds): MeasurementTemplate
    {
        return new MeasurementTemplate(
            id: (string) $model->id,
            name: $model->name,
            measureIds: $measureIds,
            sort: (int) $model->sort,
            active: (bool) $model->is_active,
            kind: MeasurementTemplateKind::tryFrom((string) $model->kind) ?? MeasurementTemplateKind::BY_SIZE,
        );
    }
}
