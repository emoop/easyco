<?php

namespace App\Filament\Resources\MeasurementTemplateResource\Pages;

use App\Filament\Resources\MeasurementTemplateResource;
use App\Filament\Support\MeasurementRefusals;
use App\Services\MeasurementTemplateWriter;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\Persistence\Eloquent\MeasurementTemplateModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Create goes through MeasurementTemplateWriter::save(): the refusals, the audit entry and the hook are the writer's. */
class CreateMeasurementTemplate extends CreateRecord
{
    protected static string $resource = MeasurementTemplateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $ids = MeasurementTemplateResource::measureIds((array) ($data['measures'] ?? []));

        try {
            $template = app(MeasurementTemplateWriter::class)->save(MeasurementTemplate::create((string) ($data['name'] ?? ''), $ids, (int) ($data['sort'] ?? 0)));
        } catch (Throwable $exception) {
            if (! MeasurementRefusals::isRefusal($exception)) {
                throw $exception;
            }

            MeasurementTemplateResource::fieldError($exception);
            MeasurementRefusals::notify($exception, 'measurements.template.notice.refused');
            $this->halt();
        }

        return MeasurementTemplateModel::findOrFail($template->id());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('measurements.template.notice.created');
    }

    protected function getRedirectUrl(): string
    {
        return MeasurementTemplateResource::getUrl('index');
    }
}
