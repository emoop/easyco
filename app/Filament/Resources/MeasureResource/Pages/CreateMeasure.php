<?php

namespace App\Filament\Resources\MeasureResource\Pages;

use App\Filament\Resources\MeasureResource;
use App\Filament\Support\MeasurementRefusals;
use App\Services\MeasureWriter;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\Persistence\Eloquent\MeasureModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Create goes through MeasureWriter::save(): the refusals, the audit entry and the hook are the writer's. */
class CreateMeasure extends CreateRecord
{
    protected static string $resource = MeasureResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $measure = app(MeasureWriter::class)->save(Measure::create((string) ($data['name'] ?? ''), (string) ($data['unit'] ?? ''), (int) ($data['sort'] ?? 0)));
        } catch (Throwable $exception) {
            if (! MeasurementRefusals::isRefusal($exception)) {
                throw $exception;
            }

            MeasureResource::fieldError($exception);
            MeasurementRefusals::notify($exception, 'measurements.measure.notice.refused');
            $this->halt();
        }

        return MeasureModel::findOrFail($measure->id());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('measurements.measure.notice.created');
    }

    protected function getRedirectUrl(): string
    {
        return MeasureResource::getUrl('index');
    }
}
