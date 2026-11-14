<?php

namespace App\Storefront\Sections;

use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Enums\MeasurementTemplateKind;
use EasyCo\Catalog\MeasurementNumber;
use EasyCo\Catalog\Services\MeasurementRows;
use Illuminate\Support\Facades\DB;

/**
 * MEAS-5: the «Таблица с размери» section source (measurements-research.md «As built (MEAS-5)»): the product's measurements printed as ONE piece of HTML for the section renderer. Nothing else reads them on the storefront.
 *
 * READS, only when a published section has this source and the merchant has not switched it off for the product (isUsed):
 *  1. the product's template, its kind and its ORDERED ACTIVE measures — ONE join (an archived template still prints, an archived MEASURE never does: owner decision 5). No rows = no template: nothing to print;
 *  2. the rows through MeasurementRows (`single`: the one product row, 0 statements; `by_size`: the size axis with the value names, 2 statements);
 *  3. the stored numbers, MeasurementValueRepository::forProduct() (1 statement).
 * No template: +1 statement. `single` + template: +2. `by_size` + template: +4 (the same for 3 or 12 sizes and measures).
 *
 * WHAT PRINTS: the numbers of the CURRENT template's active measures x the CURRENT rows, nothing else (hidden data - another template's measures, the other kind's rows, a size that left the axis - is never printed).
 * A column with no number in any printed row is dropped, a row with no number is dropped; nothing left = '' (the section is not printed). Numbers use MeasurementNumber::format() in the storefront locale, units the localised short
 * label. Every text (measure, size, axis, product name) is escaped: they are merchant data. The result is the same for every visitor.
 *
 *  by_size -> <div class="sf-table-wrap"><table class="sf-meas"> caption, header row (axis name + «Име, единица»), a row per size (th scope=row)   (the TBL-1 wrapper: scrolls inside its box, sticky first column)
 *  single  -> <dl class="sf-meas-list"><div><dt>Име</dt><dd>7,8&nbsp;мм</dd></div>...</dl>
 */
final class SectionMeasurements
{
    public function __construct(private readonly MeasurementValueRepository $values, private readonly MeasurementRows $rows)
    {
    }

    /**
     * Does a section with this source print for THIS product (defined and not switched off)?
     *
     * @param  list<array{key: string, source: string}>|null  $definitions
     * @param  array<string, mixed>  $hidden  the sections switched off for the product (key => true)
     */
    public static function isUsed(?array $definitions, array $hidden = []): bool
    {
        foreach ($definitions ?? [] as $section) {
            if ($section['source'] === 'measurements' && ! isset($hidden[$section['key']])) {
                return true;
            }
        }

        return false;
    }

    public function html(string $productId, string $productName): string
    {
        $template = DB::table('catalog_product_measurement_templates as p')
            ->join('catalog_measurement_templates as t', 't.id', '=', 'p.template_id')
            ->join('catalog_measurement_template_measures as tm', 'tm.template_id', '=', 't.id')
            ->join('catalog_measures as m', 'm.id', '=', 'tm.measure_id')
            ->where('p.product_id', $productId)
            ->where('m.is_active', true)
            ->orderBy('tm.sort')
            ->get(['t.kind', 'm.id as measure_id', 'm.name', 'm.unit']);

        if ($template->isEmpty()) {
            return '';
        }

        $kind = MeasurementTemplateKind::tryFrom((string) $template->first()->kind) ?? MeasurementTemplateKind::BY_SIZE;
        $labelled = $this->rows->labelledForProduct($productId, $kind);

        if ($labelled['rows'] === []) {
            return '';
        }

        $stored = $this->values->forProduct($productId);
        $measures = [];

        foreach ($template->all() as $row) {
            $measures[(string) $row->measure_id] = ['name' => (string) $row->name, 'unit' => (string) $row->unit];
        }

        // the intersection: current rows x current active measures; empty columns and rows are dropped
        $cells = [];
        $usedColumns = [];

        foreach ($labelled['rows'] as $rowKey => $label) {
            foreach ($measures as $measureId => $_) {
                $hundredths = $stored[(string) $rowKey][(string) $measureId] ?? null;

                if ($hundredths !== null) {
                    $cells[(string) $rowKey][(string) $measureId] = $hundredths;
                    $usedColumns[(string) $measureId] = true;
                }
            }
        }

        if ($cells === []) {
            return '';
        }

        $locale = app()->getLocale();
        $columns = array_filter($measures, fn (string $id): bool => isset($usedColumns[$id]), ARRAY_FILTER_USE_KEY);
        $unit = fn (array $measure): string => (string) __('measurements.units.'.$measure['unit']);

        if ($kind === MeasurementTemplateKind::SINGLE) {
            $html = '<dl class="sf-meas-list">';

            foreach ($columns as $measureId => $measure) {
                $hundredths = $cells[MeasurementValueRepository::PRODUCT_ROW][$measureId];
                $html .= '<div><dt>'.e($measure['name']).'</dt><dd>'.e(MeasurementNumber::format($hundredths, $locale)).'&nbsp;'.e($unit($measure)).'</dd></div>';
            }

            return $html.'</dl>';
        }

        $html = '<div class="sf-table-wrap"><table class="sf-meas"><caption class="sf-sr-only">'.e((string) __('measurements.storefront.caption', ['name' => $productName])).'</caption><thead><tr><th scope="col">'.e((string) $labelled['axis']).'</th>';

        foreach ($columns as $measure) {
            $html .= '<th scope="col">'.e($measure['name'].', '.$unit($measure)).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($labelled['rows'] as $rowKey => $label) {
            if (! isset($cells[(string) $rowKey])) {
                continue;
            }

            $html .= '<tr><th scope="row">'.e($label).'</th>';

            foreach ($columns as $measureId => $_) {
                $hundredths = $cells[(string) $rowKey][$measureId] ?? null;
                $html .= '<td>'.($hundredths === null ? '' : e(MeasurementNumber::format($hundredths, $locale))).'</td>';
            }

            $html .= '</tr>';
        }

        return $html.'</tbody></table></div>';
    }
}
