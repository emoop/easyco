<?php

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\PromotionResource;
use App\Services\Exceptions\PromotionInvalidException;
use App\Services\PromotionWriter;
use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit goes through PromotionWriter::update(). The code, the discount type and the value are shown read-only and are NOT part of the data the page sends on (they are not dehydrated); the service ignores such keys
 * anyway, so a tampered payload cannot change them. There is no delete action: a promotion has redemption history.
 */
class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PromotionModel $model */
        $model = $this->getRecord();

        return PromotionResource::stateFor($model);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(PromotionWriter::class)->update((string) $record->getKey(), $data);
        } catch (PromotionInvalidException $exception) {
            PromotionResource::fieldErrors($exception);
        }

        return $record->refresh();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('promotions.notice.updated');
    }

    protected function getRedirectUrl(): ?string
    {
        return PromotionResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
