<?php

namespace App\Filament\Resources\PriceListResource\Pages;

use App\Filament\Resources\PriceListResource;
use App\Services\Exceptions\PriceListInvalidException;
use App\Services\PriceListWriter;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit goes through PriceListWriter::update(). A system list has no edit page: PriceListResource::canEdit() is false for it, so a direct URL is a 403. There is no delete action: a price list is never deleted.
 */
class EditPriceList extends EditRecord
{
    protected static string $resource = PriceListResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PriceListModel $model */
        $model = $this->getRecord();

        return PriceListResource::stateFor($model);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(PriceListWriter::class)->update((string) $record->getKey(), $data + ['scopes' => PriceListResource::scopesFrom($data)]);
        } catch (PriceListInvalidException $exception) {
            PriceListResource::fieldErrors($exception);
        }

        return $record->refresh();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('price_lists.notice.updated');
    }

    protected function getRedirectUrl(): ?string
    {
        return PriceListResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
