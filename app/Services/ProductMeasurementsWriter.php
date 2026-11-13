<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Extensibility\Hook;

/**
 * The only way the app writes a product's measurements (MEAS-2; the product tab of MEAS-4 calls it): the template assignment and the grid of numbers. The repositories write and refuse
 * (`no_template`, `measure_not_in_template`, `size_value_not_on_axis`, `out_of_range`, unknown ids); THIS class fires ONE hook after each successful write, after the commit (CLAUDE.md rule 10):
 *
 *  catalog.product.measurements.saved (string $productId)
 *
 * It fires for assigning / switching / clearing the template and for saving the grid, because each changes what the product's measurements are. A refused write fires nothing; a throwing listener
 * propagates but cannot undo the committed write. No audit entry yet (MEAS-4 adds it with its screens).
 */
final class ProductMeasurementsWriter
{
    public function __construct(
        private readonly ProductMeasurementTemplateRepository $assignments,
        private readonly MeasurementValueRepository $values,
    ) {
    }

    public function assignTemplate(string $productId, string $templateId): void
    {
        $this->assignments->assign($productId, $templateId);

        Hook::fire('catalog.product.measurements.saved', $productId);
    }

    public function clearTemplate(string $productId): void
    {
        $this->assignments->clear($productId);

        Hook::fire('catalog.product.measurements.saved', $productId);
    }

    /** @param array<string|int, array<string|int, int|null>> $rows [size value id][measure id] => hundredths */
    public function saveGrid(string $productId, array $rows): void
    {
        $this->values->saveForProduct($productId, $rows);

        Hook::fire('catalog.product.measurements.saved', $productId);
    }
}
