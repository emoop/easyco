<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Extensibility\Hook;

/**
 * The only way the app writes a measurement template (MEAS-2): the repository writes (row + ordered list in one transaction) and refuses (name taken, unknown measure, in use); THIS class fires the hook
 * AFTER that transaction committed (CLAUDE.md rule 10). A refused write fires nothing; a throwing listener propagates but cannot undo the committed write.
 *
 *  catalog.measurement_template.saved    (MeasurementTemplate $template, bool $created)   create, rename, set ordered measures, re-sort, un-archive
 *  catalog.measurement_template.archived (MeasurementTemplate $template)                   an archive (already archived = nothing)
 *  catalog.measurement_template.deleted  (array $snapshot)                                 {id, name, measure_ids}; only a template no product uses
 *
 * AUDIT (MEAS-3): entity `measurement_template`, after the repository saved and before the hook, only for a write that succeeded: create -> `created`; an update -> one `updated` entry per changed field
 * (`name`, `kind`, `sort`, `measures` as the ordered id list `3,1,7`, `is_active`) old -> new; delete -> `deleted` with a snapshot. A save that changes nothing writes no audit entry.
 */
final class MeasurementTemplateWriter
{
    public const ENTITY = 'measurement_template';

    public function __construct(private readonly MeasurementTemplateRepository $templates, private readonly ActivityLogger $audit)
    {
    }

    public function save(MeasurementTemplate $template): MeasurementTemplate
    {
        $created = $template->id() === null;
        $before = $created ? null : $this->templates->findById((string) $template->id());

        $this->templates->save($template);

        $this->logChanges($before, $template);

        Hook::fire('catalog.measurement_template.saved', $template, $created);

        return $template;
    }

    public function archive(string $id): MeasurementTemplate
    {
        $template = $this->find($id);

        if ($template->isActive()) {
            $template->archive();
            $this->templates->save($template);
            $this->audit->logFieldChanged(self::ENTITY, $id, 'is_active', '1', '0');

            Hook::fire('catalog.measurement_template.archived', $template);
        }

        return $template;
    }

    public function unarchive(string $id): MeasurementTemplate
    {
        $template = $this->find($id);

        if (! $template->isActive()) {
            $template->unarchive();
            $this->templates->save($template);
            $this->audit->logFieldChanged(self::ENTITY, $id, 'is_active', '0', '1');

            Hook::fire('catalog.measurement_template.saved', $template, false);
        }

        return $template;
    }

    /** @throws \EasyCo\Catalog\Exceptions\MeasurementRefusedException `template_in_use` */
    public function delete(string $id): void
    {
        $template = $this->find($id);
        $snapshot = ['id' => $id, 'name' => $template->name(), 'measure_ids' => $template->measureIds()];

        $this->templates->delete($id);
        $this->audit->logDeleted(self::ENTITY, $id, $snapshot);

        Hook::fire('catalog.measurement_template.deleted', $snapshot);
    }

    private function logChanges(?MeasurementTemplate $before, MeasurementTemplate $after): void
    {
        $id = (string) $after->id();

        if ($before === null) {
            $this->audit->logCreated(self::ENTITY, $id);

            return;
        }

        $fields = [
            'name' => [$before->name(), $after->name()],
            'kind' => [$before->kind()->value, $after->kind()->value],
            'sort' => [(string) $before->sort(), (string) $after->sort()],
            'measures' => [implode(',', $before->measureIds()), implode(',', $after->measureIds())],
            'is_active' => [$before->isActive() ? '1' : '0', $after->isActive() ? '1' : '0'],
        ];

        foreach ($fields as $field => [$old, $new]) {
            if ($old !== $new) {
                $this->audit->logFieldChanged(self::ENTITY, $id, $field, $old, $new);
            }
        }
    }

    private function find(string $id): MeasurementTemplate
    {
        return $this->templates->findById($id) ?? throw InvalidMeasurementValueException::unknownReference('template', $id);
    }
}
