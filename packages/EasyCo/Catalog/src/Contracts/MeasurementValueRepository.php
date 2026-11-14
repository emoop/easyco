<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;

/**
 * The typed numbers of a product's measurement grid (MEAS-2), as integer hundredths keyed [size value id][measure id].
 */
interface MeasurementValueRepository
{
    /** The row key of the ONE row of a `single`-kind product (stored as a NULL size value). Every array of this contract uses it in place of a size value id. */
    public const PRODUCT_ROW = 'product';

    /**
     * EVERY stored number of the product in ONE statement. It does not filter by the current template or size axis (that is a second and third read the caller already has): a number of a measure
     * the template no longer lists, or of a size value that left the axis, is returned and is simply ignored by the readers, never fatal.
     *
     * @return array<string, array<string, int>>  [size value id | PRODUCT_ROW][measure id] => hundredths
     */
    public function forProduct(string $productId): array;

    /**
     * Replace the product's grid in ONE transaction: every cell of $rows is upserted when it changed, and every stored cell INSIDE THE CURRENT GRID (a measure of the assigned template x a size value on the
     * product's size axis, or the PRODUCT_ROW of a `single` template) that is not in $rows is deleted.
     * A `by_size` template accepts only size values of the axis (the PRODUCT_ROW key is `size_value_not_on_axis`); a `single` template accepts ONLY the PRODUCT_ROW key (anything else is `single_row_only`). Cells outside the grid (hidden numbers: a measure the template dropped, a size value that left the axis) are kept untouched.
     * A cell of null or absent = no value. Everything is validated first; on any refusal nothing is written.
     *
     * @param  array<string|int, array<string|int, int|null>>  $rows  [size value id][measure id] => hundredths
     *
     * @throws InvalidMeasurementValueException  `no_template`, `measure_not_in_template`, `size_value_not_on_axis`, `single_row_only`, `out_of_range`
     */
    public function saveForProduct(string $productId, array $rows): void;

    /**
     * Copy EVERY stored number of one product to another (MEAS-5, a product duplicate) in ONE insert-select statement, whatever the number of cells. The values are keyed by global attribute value ids and measures,
     * so they stay valid for the copy; no per-cell validation (the source data was already valid). A cell the target already has is left alone. Returns the number of cells copied.
     */
    public function copyProduct(string $fromProductId, string $toProductId): int;
}
