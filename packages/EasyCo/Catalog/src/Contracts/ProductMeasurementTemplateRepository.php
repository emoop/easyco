<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;

/**
 * Which measurement template a product uses (MEAS-2). A separate table (catalog_product_measurement_templates), not a column of catalog_products: Product.php is frozen. At most one per product.
 * Switching or clearing the template NEVER touches the stored numbers (they are keyed by measure, not by template): the numbers of measures the new template lacks are just not shown.
 */
interface ProductMeasurementTemplateRepository
{
    public function templateIdFor(string $productId): ?string;

    /**
     * Assign (or switch to) a template.
     *
     * @throws InvalidMeasurementValueException  `unknown_reference` for a product or template that does not exist
     */
    public function assign(string $productId, string $templateId): void;

    public function clear(string $productId): void;
}
