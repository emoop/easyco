<?php

namespace App\Filament\Resources\MeasurementTemplateResource\Pages;

use App\Filament\Resources\MeasurementTemplateResource;
use App\Filament\Support\MeasurementRefusals;
use App\Services\MeasurementTemplateWriter;
use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\Persistence\Eloquent\MeasurementTemplateModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Edit goes through MeasurementTemplateWriter::save(). The form's measure list starts as the template's stored ordered list (archived measures included, so a save keeps them); the writer keeps the order.
 */
class EditMeasurementTemplate extends EditRecord
{
    protected static string $resource = MeasurementTemplateResource::class;

    /** @return string[] the template's stored measure ids, in order */
    public function ownMeasureIds(): array
    {
        return app(MeasurementTemplateRepository::class)->findById((string) $this->getRecord()->getKey())?->measureIds() ?? [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['measures'] = $this->ownMeasureIds();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $template = app(MeasurementTemplateRepository::class)->findById((string) $record->id);

        if ($template === null) {
            MeasurementRefusals::notify(InvalidMeasurementValueException::unknownReference('template', (string) $record->id), 'measurements.template.notice.refused');
            $this->halt();
        }

        $ids = MeasurementTemplateResource::measureIds((array) ($data['measures'] ?? []), $template->measureIds());

        try {
            $changed = false;

            if ((string) ($data['name'] ?? $template->name()) !== $template->name()) {
                $template->rename((string) $data['name']);
                $changed = true;
            }

            if (isset($data['sort']) && (int) $data['sort'] !== $template->sort()) {
                $template->changeSort((int) $data['sort']);
                $changed = true;
            }

            if ($ids !== $template->measureIds()) {
                $template->setMeasureIds($ids);
                $changed = true;
            }

            if ($changed) {
                app(MeasurementTemplateWriter::class)->save($template);
            }
        } catch (Throwable $exception) {
            if (! MeasurementRefusals::isRefusal($exception)) {
                throw $exception;
            }

            MeasurementTemplateResource::fieldError($exception);
            MeasurementRefusals::notify($exception, 'measurements.template.notice.refused');
            $this->halt();
        }

        return MeasurementTemplateModel::findOrFail($record->id);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('measurements.template.notice.updated');
    }

    protected function getRedirectUrl(): ?string
    {
        return MeasurementTemplateResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
