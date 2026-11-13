<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\Measure;
use EasyCo\Extensibility\Hook;

/**
 * The only way the app writes a measure (MEAS-2; the admin screens of MEAS-3 call it): the repository does the write and the refusals (name taken, unit locked, in use), THIS class fires the hook
 * AFTER the repository's transaction has committed — domain packages never call Hook (CLAUDE.md rule 10). A refused or failed write fires nothing. Like ShippingClassWriter, a throwing listener
 * propagates its exception to the caller but cannot undo or corrupt the write, which is already committed.
 *
 *  catalog.measure.saved    (Measure $measure, bool $created)   after a create, rename, re-sort, unit change, or un-archive
 *  catalog.measure.archived (Measure $measure)                   after an archive (a measure that was already archived fires nothing)
 *  catalog.measure.deleted  (array $snapshot)                    after a hard delete: {id, name, unit}
 *
 * No audit entry yet: the admin task (MEAS-3) adds the ActivityLogger lines together with its screens.
 */
final class MeasureWriter
{
    public function __construct(private readonly MeasureRepository $measures)
    {
    }

    public function save(Measure $measure): Measure
    {
        $created = $measure->id() === null;

        $this->measures->save($measure);

        Hook::fire('catalog.measure.saved', $measure, $created);

        return $measure;
    }

    public function archive(string $id): Measure
    {
        $measure = $this->find($id);

        if ($measure->isActive()) {
            $measure->archive();
            $this->measures->save($measure);

            Hook::fire('catalog.measure.archived', $measure);
        }

        return $measure;
    }

    public function unarchive(string $id): Measure
    {
        $measure = $this->find($id);

        if (! $measure->isActive()) {
            $measure->unarchive();
            $this->measures->save($measure);

            Hook::fire('catalog.measure.saved', $measure, false);
        }

        return $measure;
    }

    /** @throws \EasyCo\Catalog\Exceptions\MeasurementRefusedException `measure_in_use`: archive instead */
    public function delete(string $id): void
    {
        $measure = $this->find($id);
        $snapshot = ['id' => $id, 'name' => $measure->name(), 'unit' => $measure->unit()->value];

        $this->measures->delete($id);

        Hook::fire('catalog.measure.deleted', $snapshot);
    }

    private function find(string $id): Measure
    {
        return $this->measures->findById($id) ?? throw InvalidMeasurementValueException::unknownReference('measure', $id);
    }
}
