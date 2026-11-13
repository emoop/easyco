<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\SizeAxisResolver;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\MeasurementNumber;
use Illuminate\Support\Facades\DB;

/**
 * Maps the grid of typed numbers onto catalog_measurement_values (unique per product + size value + measure, so a cell is a real constraint — CLAUDE.md rule 2). The "measure is in the template" and
 * "size value is on the axis" rules are the application's (a pivot cannot express them): checked here, before anything is written, from the assignment repository and the size-axis resolver.
 */
final class EloquentMeasurementValueRepository implements MeasurementValueRepository
{
    public function __construct(
        private readonly ProductMeasurementTemplateRepository $assignments,
        private readonly SizeAxisResolver $axis,
    ) {
    }

    public function forProduct(string $productId): array
    {
        $grid = [];

        foreach (DB::table('catalog_measurement_values')->where('product_id', $productId)->get(['size_value_id', 'measure_id', 'value_hundredths']) as $row) {
            $grid[(string) $row->size_value_id][(string) $row->measure_id] = (int) $row->value_hundredths;
        }

        return $grid;
    }

    public function saveForProduct(string $productId, array $rows): void
    {
        DB::transaction(function () use ($productId, $rows): void {
            $templateId = $this->assignments->templateIdFor($productId);

            $desired = [];
            foreach ($rows as $sizeValueId => $cells) {
                foreach ((array) $cells as $measureId => $hundredths) {
                    $desired[(string) $sizeValueId][(string) $measureId] = $hundredths === null ? null : (int) $hundredths;
                }
            }

            $hasNumber = false;
            foreach ($desired as $cells) {
                foreach ($cells as $hundredths) {
                    $hasNumber = $hasNumber || $hundredths !== null;
                }
            }

            if ($templateId === null) {
                // no template = no grid: nothing may be written, and an empty grid has nothing to replace
                if ($hasNumber || $desired !== []) {
                    throw InvalidMeasurementValueException::noTemplate($productId);
                }

                return;
            }

            $templateMeasures = DB::table('catalog_measurement_template_measures')->where('template_id', $templateId)->pluck('measure_id')->map(fn ($id) => (string) $id)->all();
            $axisSizes = $this->axis->forProduct($productId);

            // 1. validate everything first: on any refusal nothing is written
            foreach ($desired as $sizeKey => $cells) {
                $sizeValueId = (string) $sizeKey;
                if (! in_array($sizeValueId, $axisSizes, true)) {
                    throw InvalidMeasurementValueException::sizeValueNotOnAxis($productId, $sizeValueId);
                }

                foreach ($cells as $measureKey => $hundredths) {
                    $measureId = (string) $measureKey;
                    if (! in_array($measureId, $templateMeasures, true)) {
                        throw InvalidMeasurementValueException::measureNotInTemplate($productId, $measureId);
                    }

                    if ($hundredths !== null) {
                        MeasurementNumber::assertInRange($hundredths);
                    }
                }
            }

            // 2. diff against the stored grid
            $existing = [];
            foreach (DB::table('catalog_measurement_values')->where('product_id', $productId)->get(['id', 'size_value_id', 'measure_id', 'value_hundredths']) as $row) {
                $existing[(string) $row->size_value_id][(string) $row->measure_id] = ['id' => $row->id, 'value' => (int) $row->value_hundredths];
            }

            $now = now();
            $insert = [];
            $deleteIds = [];

            // a stored cell INSIDE the current grid that the caller no longer sends (or sends empty) is removed; a cell outside the grid is hidden data and stays
            foreach ($existing as $sizeKey => $cells) {
                $sizeValueId = (string) $sizeKey;
                if (! in_array($sizeValueId, $axisSizes, true)) {
                    continue;
                }

                foreach ($cells as $measureKey => $cell) {
                    $measureId = (string) $measureKey;
                    if (in_array($measureId, $templateMeasures, true) && ($desired[$sizeValueId][$measureId] ?? null) === null) {
                        $deleteIds[] = $cell['id'];
                    }
                }
            }

            foreach ($desired as $sizeKey => $cells) {
                $sizeValueId = (string) $sizeKey;
                foreach ($cells as $measureKey => $hundredths) {
                    $measureId = (string) $measureKey;
                    if ($hundredths === null) {
                        continue;
                    }

                    $stored = $existing[$sizeValueId][$measureId] ?? null;

                    if ($stored === null) {
                        $insert[] = [
                            'product_id' => $productId,
                            'size_value_id' => $sizeValueId,
                            'measure_id' => $measureId,
                            'value_hundredths' => $hundredths,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    } elseif ($stored['value'] !== $hundredths) {
                        DB::table('catalog_measurement_values')->where('id', $stored['id'])->update(['value_hundredths' => $hundredths, 'updated_at' => $now]);
                    }
                }
            }

            if ($deleteIds !== []) {
                DB::table('catalog_measurement_values')->whereIn('id', $deleteIds)->delete();
            }

            if ($insert !== []) {
                DB::table('catalog_measurement_values')->insert($insert);
            }
        });
    }
}
