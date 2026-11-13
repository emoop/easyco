<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\Exceptions\MeasurementRefusedException;
use EasyCo\Catalog\MeasurementTemplate;

/**
 * Persistence contract for measurement templates (MEAS-2). `save()` writes the template AND its ordered measure list in one transaction (create, rename, set ordered measures, archive are all a save).
 */
interface MeasurementTemplateRepository
{
    /**
     * @throws MeasurementRefusedException  `name_taken`
     * @throws InvalidMeasurementValueException  `unknown_reference` when a listed measure id does not exist
     */
    public function save(MeasurementTemplate $template): void;

    public function findById(string $id): ?MeasurementTemplate;

    /** @return MeasurementTemplate[] ordered by sort then id; the measure ids inside are in the template's order */
    public function all(): array;

    /** How many products are assigned this template. */
    public function countProductsUsing(string $id): int;

    /**
     * Hard delete only while no product uses it (the list rows cascade).
     *
     * @throws MeasurementRefusedException  `template_in_use`
     */
    public function delete(string $id): void;
}
