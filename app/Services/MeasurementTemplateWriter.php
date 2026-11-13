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
 */
final class MeasurementTemplateWriter
{
    public function __construct(private readonly MeasurementTemplateRepository $templates)
    {
    }

    public function save(MeasurementTemplate $template): MeasurementTemplate
    {
        $created = $template->id() === null;

        $this->templates->save($template);

        Hook::fire('catalog.measurement_template.saved', $template, $created);

        return $template;
    }

    public function archive(string $id): MeasurementTemplate
    {
        $template = $this->find($id);

        if ($template->isActive()) {
            $template->archive();
            $this->templates->save($template);

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

        Hook::fire('catalog.measurement_template.deleted', $snapshot);
    }

    private function find(string $id): MeasurementTemplate
    {
        return $this->templates->findById($id) ?? throw InvalidMeasurementValueException::unknownReference('template', $id);
    }
}
