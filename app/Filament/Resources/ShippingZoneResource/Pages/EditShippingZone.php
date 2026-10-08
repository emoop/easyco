<?php

namespace App\Filament\Resources\ShippingZoneResource\Pages;

use App\Filament\Resources\ShippingZoneResource;
use App\Services\Exceptions\ShippingZoneInvalidException;
use App\Services\Exceptions\ShippingZoneNotFoundException;
use App\Services\ShippingZoneWriter;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit goes through ShippingZoneWriter::update(): it NEVER changes the order (the form has no order field).
 * The page reads the zone once (Filament resolves the record); the JSON lists are decoded for the form.
 */
class EditShippingZone extends EditRecord
{
    protected static string $resource = ShippingZoneResource::class;

    /** The record holds the three lists as JSON text; the form edits them as lists. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['country_codes'] = ShippingZoneResource::decodeList($data['country_codes'] ?? null) ?? [];
        $data['settlement_names'] = ShippingZoneResource::decodeList($data['settlement_names'] ?? null) ?? [];
        $data['postcodes'] = ShippingZoneResource::decodeList($data['postcodes'] ?? null) ?? [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(ShippingZoneWriter::class)->update(
                (string) $record->id,
                (string) ($data['name'] ?? ''),
                array_values((array) ($data['country_codes'] ?? [])),
                array_values((array) ($data['settlement_names'] ?? [])),
                array_values((array) ($data['postcodes'] ?? [])),
            );
        } catch (ShippingZoneInvalidException $exception) {
            ShippingZoneResource::fieldErrors($exception);
        } catch (ShippingZoneNotFoundException $exception) {
            Notification::make()->title(__('shipping.zones.notice.refused'))->body($exception->getMessage())->danger()->send();
            $this->halt();
        }

        return $record->refresh();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('shipping.zones.notice.updated');
    }

    protected function getRedirectUrl(): ?string
    {
        return ShippingZoneResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
