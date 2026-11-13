<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MEAS-3: the «Използва се в» numbers of the two admin lists, for ALL listed rows in grouped reads (never one query per row): a measure's templates and stored numbers (two statements), a template's
 * products (one statement) and its measures' names (one statement). The single-row truth stays MeasureRepository::usage() / MeasurementTemplateRepository::countProductsUsing(), which the delete rule
 * uses; this class only batches the same counts for a list.
 */
final class MeasurementUsageReader
{
    /**
     * @param  array<int|string>  $measureIds
     * @return array<string, array{templates: int, values: int}>
     */
    public function forMeasures(array $measureIds): array
    {
        $out = [];

        foreach ($measureIds as $id) {
            $out[(string) $id] = ['templates' => 0, 'values' => 0];
        }

        if ($out === []) {
            return $out;
        }

        $ids = array_keys($out);

        foreach (DB::table('catalog_measurement_template_measures')->whereIn('measure_id', $ids)->groupBy('measure_id')->selectRaw('measure_id, COUNT(*) as n')->get() as $row) {
            $out[(string) $row->measure_id]['templates'] = (int) $row->n;
        }

        foreach (DB::table('catalog_measurement_values')->whereIn('measure_id', $ids)->groupBy('measure_id')->selectRaw('measure_id, COUNT(*) as n')->get() as $row) {
            $out[(string) $row->measure_id]['values'] = (int) $row->n;
        }

        return $out;
    }

    /**
     * @param  array<int|string>  $templateIds
     * @return array<string, int> template id => products using it
     */
    public function productsForTemplates(array $templateIds): array
    {
        $out = [];

        foreach ($templateIds as $id) {
            $out[(string) $id] = 0;
        }

        if ($out === []) {
            return $out;
        }

        foreach (DB::table('catalog_product_measurement_templates')->whereIn('template_id', array_keys($out))->groupBy('template_id')->selectRaw('template_id, COUNT(*) as n')->get() as $row) {
            $out[(string) $row->template_id] = (int) $row->n;
        }

        return $out;
    }

    /**
     * @param  array<int|string>  $templateIds
     * @return array<string, string[]> template id => the names of its measures, in the template's order
     */
    public function measureNamesForTemplates(array $templateIds): array
    {
        $out = [];

        foreach ($templateIds as $id) {
            $out[(string) $id] = [];
        }

        if ($out === []) {
            return $out;
        }

        $rows = DB::table('catalog_measurement_template_measures as tm')
            ->join('catalog_measures as m', 'm.id', '=', 'tm.measure_id')
            ->whereIn('tm.template_id', array_keys($out))
            ->orderBy('tm.template_id')->orderBy('tm.sort')
            ->get(['tm.template_id', 'm.name']);

        foreach ($rows as $row) {
            $out[(string) $row->template_id][] = (string) $row->name;
        }

        return $out;
    }
}
