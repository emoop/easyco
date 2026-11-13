<?php

namespace App\Filament\Resources\MeasureResource\Pages;

use App\Filament\Resources\MeasureResource;
use App\Filament\Support\MeasurementRefusals;
use App\Services\MeasureWriter;
use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Persistence\Eloquent\MeasureModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Edit goes through MeasureWriter::save(). The unit select is disabled when numbers exist (decided when the page OPENED, `unitLocked`); a form that was opened before a number was stored, or a tampered
 * request, still reaches the repository's `unit_locked` refusal, which is shown as its translated notice and saves nothing.
 */
class EditMeasure extends EditRecord
{
    protected static string $resource = MeasureResource::class;

    public bool $unitLocked = false;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        $this->unitLocked = app(MeasureRepository::class)->usage((string) $this->getRecord()->getKey())['values'] > 0;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $measures = app(MeasureRepository::class);
        $measure = $measures->findById((string) $record->id);

        if ($measure === null) {
            MeasurementRefusals::notify(\EasyCo\Catalog\Exceptions\InvalidMeasurementValueException::unknownReference('measure', (string) $record->id), 'measurements.measure.notice.refused');
            $this->halt();
        }

        try {
            $changed = false;

            // a disabled field is not submitted: a missing `unit` means "no change"
            if ((string) ($data['name'] ?? $measure->name()) !== $measure->name()) {
                $measure->rename((string) $data['name']);
                $changed = true;
            }

            if (isset($data['unit']) && (string) $data['unit'] !== $measure->unit()->value) {
                $measure->changeUnit((string) $data['unit']);
                $changed = true;
            }

            if (isset($data['sort']) && (int) $data['sort'] !== $measure->sort()) {
                $measure->changeSort((int) $data['sort']);
                $changed = true;
            }

            if ($changed) {
                app(MeasureWriter::class)->save($measure);
            }
        } catch (Throwable $exception) {
            if (! MeasurementRefusals::isRefusal($exception)) {
                throw $exception;
            }

            MeasureResource::fieldError($exception);
            MeasurementRefusals::notify($exception, 'measurements.measure.notice.refused');
            $this->halt();
        }

        return MeasureModel::findOrFail($record->id);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('measurements.measure.notice.updated');
    }

    protected function getRedirectUrl(): ?string
    {
        return MeasureResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
