<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Exceptions\MeasurementRefusedException;
use EasyCo\Catalog\Measure;

/**
 * Persistence contract for the merchant's list of measures (MEAS-2). Create, rename, re-sort and archive are all `save()` of the changed entity.
 */
interface MeasureRepository
{
    /**
     * @throws MeasurementRefusedException  `name_taken` (the unique index, detected by SQLSTATE + driver code) or `unit_locked` (the unit changed while a number exists)
     */
    public function save(Measure $measure): void;

    public function findById(string $id): ?Measure;

    /** @return Measure[] every measure, ordered by sort then id */
    public function all(): array;

    /** @return Measure[] only the non-archived ones, same order */
    public function active(): array;

    /** @return array{templates: int, values: int} how many templates list it and how many stored numbers use it */
    public function usage(string $id): array;

    /**
     * Hard delete ONLY of a measure that no template lists and no number uses.
     *
     * @throws MeasurementRefusedException  `measure_in_use` ("archive it instead")
     */
    public function delete(string $id): void;
}
