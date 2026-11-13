<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;

/**
 * The typed numbers of a product's measurement grid (MEAS-2), as integer hundredths keyed [size value id][measure id].
 */
interface MeasurementValueRepository
{
    /**
     * EVERY stored number of the product in ONE statement. It does not filter by the current template or size axis (that is a second and third read the caller already has): a number of a measure
     * the template no longer lists, or of a size value that left the axis, is returned and is simply ignored by the readers, never fatal.
     *
     * @return array<string, array<string, int>>
     */
    public function forProduct(string $productId): array;

    /**
     * Replace the product's grid in ONE transaction: every cell of $rows is upserted when it changed, and every stored cell INSIDE THE CURRENT GRID (a measure of the assigned template x a size value on the
     * product's size axis) that is not in $rows is deleted. Cells outside the grid (hidden numbers: a measure the template dropped, a size value that left the axis) are kept untouched.
     * A cell of null or absent = no value. Everything is validated first; on any refusal nothing is written.
     *
     * @param  array<string|int, array<string|int, int|null>>  $rows  [size value id][measure id] => hundredths
     *
     * @throws InvalidMeasurementValueException  `no_template`, `measure_not_in_template`, `size_value_not_on_axis`, `out_of_range`
     */
    public function saveForProduct(string $productId, array $rows): void;
}
