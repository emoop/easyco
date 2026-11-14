<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\MeasurementNumber;
use EasyCo\Extensibility\Hook;
use Illuminate\Support\Facades\DB;

/**
 * The only way the app writes a product's measurements (MEAS-2; the product tab of MEAS-4 calls it): the template assignment and the grid of numbers. The repositories write and refuse
 * (`no_template`, `measure_not_in_template`, `size_value_not_on_axis`, `single_row_only`, `out_of_range`, unknown ids); THIS class audits and fires ONE hook after each successful write, after the commit (CLAUDE.md rule 10):
 *
 *  catalog.product.measurements.saved (string $productId)
 *
 * It fires for assigning / switching / clearing the template and for saving the grid, because each changes what the product's measurements are. A refused write fires nothing and writes no audit entry;
 * a throwing listener propagates but cannot undo the committed write.
 *
 * AUDIT (MEAS-4), entity `product`, after the write and before the hook, nothing on a refusal or a no-op; field entries obey `admin.activity_log_enabled` (ActivityLogger):
 *  - the template: field `measurement_template`, old name -> new name (— for none);
 *  - the grid: one `updated` entry per CHANGED cell, field `measurement[<size value>][<measure>]` (readable names; the single row of a `single` template is written `product`), old -> new in the admin
 *    format, an empty side is null. When more than SUMMARY_FROM cells changed in one save: ONE entry, field `measurements`, new value "N клетки променени" instead (no 200-row floods).
 *    The changed cells are found by reading the grid before and after the write (2 statements, whatever the grid size).
 */
final class ProductMeasurementsWriter
{
    public const ENTITY = 'product';

    /** More changed cells than this in one save are logged as one summary entry. */
    public const SUMMARY_FROM = 20;

    public function __construct(
        private readonly ProductMeasurementTemplateRepository $assignments,
        private readonly MeasurementValueRepository $values,
        private readonly ActivityLogger $audit,
        private readonly MeasurementTemplateRepository $templates,
    ) {
    }

    public function assignTemplate(string $productId, string $templateId): void
    {
        $before = $this->templateName($this->assignments->templateIdFor($productId));

        $this->assignments->assign($productId, $templateId);

        $this->logTemplate($productId, $before, $this->templateName($templateId));

        Hook::fire('catalog.product.measurements.saved', $productId);
    }

    public function clearTemplate(string $productId): void
    {
        $before = $this->templateName($this->assignments->templateIdFor($productId));

        $this->assignments->clear($productId);

        $this->logTemplate($productId, $before, null);

        Hook::fire('catalog.product.measurements.saved', $productId);
    }

    /** @param array<string|int, array<string|int, int|null>> $rows [size value id | MeasurementValueRepository::PRODUCT_ROW][measure id] => hundredths */
    public function saveGrid(string $productId, array $rows): void
    {
        $before = $this->values->forProduct($productId);

        $this->values->saveForProduct($productId, $rows);

        $this->logCells($productId, $before, $this->values->forProduct($productId));

        Hook::fire('catalog.product.measurements.saved', $productId);
    }

    /**
     * A product DUPLICATE keeps the specs of its source (MEAS-5; supersedes the MEAS-2 «nothing is copied» decision): the template assignment and EVERY stored number, through ONE insert-select (the statements do not
     * depend on the number of cells). The source data was already valid, so nothing is validated per cell. ONE audit entry (field `measurements`, «копирани от <source>») and ONE hook; a source with no template and
     * no numbers copies nothing and writes nothing. A `by_size` copy onto a variable duplicate (which starts with no axes) simply stays hidden until the duplicate declares the same sizes.
     */
    public function copyFromProduct(string $fromProductId, string $toProductId, string $sourceLabel): void
    {
        $templateId = $this->assignments->templateIdFor($fromProductId);
        $copied = $this->values->copyProduct($fromProductId, $toProductId);

        if ($templateId === null && $copied === 0) {
            return;
        }

        $templateId === null || $this->assignments->assign($toProductId, $templateId);

        $this->audit->logFieldChanged(self::ENTITY, $toProductId, 'measurements', null, __('measurements.product_tab.audit_copied', ['source' => $sourceLabel]));

        Hook::fire('catalog.product.measurements.saved', $toProductId);
    }

    private function templateName(?string $templateId): ?string
    {
        return $templateId === null ? null : $this->templates->findById($templateId)?->name();
    }

    private function logTemplate(string $productId, ?string $old, ?string $new): void
    {
        if ($old !== $new) {
            $this->audit->logFieldChanged(self::ENTITY, $productId, 'measurement_template', $old ?? '—', $new ?? '—');
        }
    }

    /**
     * @param  array<string, array<string, int>>  $before
     * @param  array<string, array<string, int>>  $after
     */
    private function logCells(string $productId, array $before, array $after): void
    {
        $changed = [];

        foreach ([$before, $after] as $grid) {
            foreach ($grid as $row => $cells) {
                foreach ($cells as $measure => $_) {
                    $old = $before[$row][$measure] ?? null;
                    $new = $after[$row][$measure] ?? null;

                    if ($old !== $new) {
                        $changed[(string) $row.'|'.(string) $measure] = [(string) $row, (string) $measure, $old, $new];
                    }
                }
            }
        }

        if ($changed === []) {
            return;
        }

        if (count($changed) > self::SUMMARY_FROM) {
            $this->audit->logFieldChanged(self::ENTITY, $productId, 'measurements', null, __('measurements.product_tab.audit_cells_changed', ['count' => count($changed)]));

            return;
        }

        $sizeNames = DB::table('catalog_attribute_values')->whereIn('id', array_values(array_filter(array_column($changed, 0), fn ($row) => $row !== MeasurementValueRepository::PRODUCT_ROW)))->pluck('value', 'id')->all();
        $measureNames = DB::table('catalog_measures')->whereIn('id', array_column($changed, 1))->pluck('name', 'id')->all();
        $locale = app()->getLocale();

        foreach ($changed as [$row, $measure, $old, $new]) {
            $rowName = $row === MeasurementValueRepository::PRODUCT_ROW ? $row : ($sizeNames[$row] ?? $row);

            $this->audit->logFieldChanged(
                self::ENTITY,
                $productId,
                'measurement['.$rowName.']['.($measureNames[$measure] ?? $measure).']',
                $old === null ? null : MeasurementNumber::format($old, $locale),
                $new === null ? null : MeasurementNumber::format($new, $locale),
            );
        }
    }
}
