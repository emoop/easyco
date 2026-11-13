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
 * AUDIT (MEAS-3): entity `measure`, written AFTER the repository saved and BEFORE the hook, only for a write that succeeded (a refusal writes nothing): create -> `created`; an update -> one `updated`
 * entry per changed field (`name`, `unit`, `sort`, `is_active`) old -> new; delete -> `deleted` with a snapshot. A save that changes nothing writes no audit entry.
 */
final class MeasureWriter
{
    public const ENTITY = 'measure';

    public function __construct(private readonly MeasureRepository $measures, private readonly ActivityLogger $audit)
    {
    }

    public function save(Measure $measure): Measure
    {
        $created = $measure->id() === null;
        $before = $created ? null : $this->measures->findById((string) $measure->id());

        $this->measures->save($measure);

        $this->logChanges($before, $measure);

        Hook::fire('catalog.measure.saved', $measure, $created);

        return $measure;
    }

    public function archive(string $id): Measure
    {
        $measure = $this->find($id);

        if ($measure->isActive()) {
            $measure->archive();
            $this->measures->save($measure);
            $this->audit->logFieldChanged(self::ENTITY, $id, 'is_active', '1', '0');

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
            $this->audit->logFieldChanged(self::ENTITY, $id, 'is_active', '0', '1');

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
        $this->audit->logDeleted(self::ENTITY, $id, $snapshot);

        Hook::fire('catalog.measure.deleted', $snapshot);
    }

    private function logChanges(?Measure $before, Measure $after): void
    {
        $id = (string) $after->id();

        if ($before === null) {
            $this->audit->logCreated(self::ENTITY, $id);

            return;
        }

        $fields = [
            'name' => [$before->name(), $after->name()],
            'unit' => [$before->unit()->value, $after->unit()->value],
            'sort' => [(string) $before->sort(), (string) $after->sort()],
            'is_active' => [$before->isActive() ? '1' : '0', $after->isActive() ? '1' : '0'],
        ];

        foreach ($fields as $field => [$old, $new]) {
            if ($old !== $new) {
                $this->audit->logFieldChanged(self::ENTITY, $id, $field, $old, $new);
            }
        }
    }

    private function find(string $id): Measure
    {
        return $this->measures->findById($id) ?? throw InvalidMeasurementValueException::unknownReference('measure', $id);
    }
}
