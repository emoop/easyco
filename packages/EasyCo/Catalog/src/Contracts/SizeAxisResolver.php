<?php

namespace EasyCo\Catalog\Contracts;

/**
 * Which variation axis supplies the ROWS of a product's measurement grid, and which size values it has (MEAS-2).
 *
 * Rule: among the product's variation axes (catalog_product_attributes.is_variation_axis) take the one whose attribute definition is flagged `is_size`; if none is flagged, the FIRST declared axis
 * (lowest sort_order, then id); a SIMPLE product (no axes) has none. A colour-only product therefore uses its colour as the rows. The size values are the axis values the product declared
 * (catalog_product_axis_values), in the attribute's own order (catalog_attribute_values.sort_order, then id). A pure read: at most 3 statements, whatever the number of sizes.
 */
interface SizeAxisResolver
{
    /**
     * @return string[] the size value ids in display order; [] for a simple product or one without axes
     */
    public function forProduct(string $productId): array;

    /** The attribute definition id of the axis that supplies the rows, or null when there is none. Same statements as forProduct(). */
    public function axisDefinitionIdFor(string $productId): ?string;
}
