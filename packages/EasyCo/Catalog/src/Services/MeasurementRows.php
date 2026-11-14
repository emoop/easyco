<?php

namespace EasyCo\Catalog\Services;

use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\SizeAxisResolver;
use EasyCo\Catalog\Enums\MeasurementTemplateKind;

/**
 * The ROWS of a product's measurement grid for a template kind (MEAS-4) — the one reader the admin tab and the storefront (MEAS-5) share, so they can never disagree.
 *
 *  - `single`  -> [PRODUCT_ROW]: one row for the whole product, whatever its variants (0 statements);
 *  - `by_size` -> the size values of the product's size axis in the attribute's order (SizeAxisResolver: 2 statements, 1 for a simple product); a simple product therefore has NO rows.
 */
final class MeasurementRows
{
    public function __construct(private readonly SizeAxisResolver $axis)
    {
    }

    /** @return string[] row keys: size value ids, or [MeasurementValueRepository::PRODUCT_ROW] */
    public function forProduct(string $productId, MeasurementTemplateKind $kind): array
    {
        return $kind === MeasurementTemplateKind::SINGLE
            ? [MeasurementValueRepository::PRODUCT_ROW]
            : $this->axis->forProduct($productId);
    }

    /**
     * The same rows with their texts (MEAS-5: the storefront prints the size names and the axis name). `single`: the one product row (no label, no axis, 0 statements). `by_size`: the axis' values in the same order as
     * forProduct(), 2 statements (1 for a simple product, which has no rows).
     *
     * @return array{axis: string|null, rows: array<string, string>} row key => label
     */
    public function labelledForProduct(string $productId, MeasurementTemplateKind $kind): array
    {
        if ($kind === MeasurementTemplateKind::SINGLE) {
            return ['axis' => null, 'rows' => [MeasurementValueRepository::PRODUCT_ROW => '']];
        }

        $axis = $this->axis->axisWithValues($productId);

        return $axis === null ? ['axis' => null, 'rows' => []] : ['axis' => $axis['name'], 'rows' => $axis['values']];
    }
}
