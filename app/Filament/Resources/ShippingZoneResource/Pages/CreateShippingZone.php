<?php

namespace App\Filament\Resources\ShippingZoneResource\Pages;

use App\Filament\Resources\ShippingZoneResource;
use App\Services\Exceptions\ShippingZoneInvalidException;
use App\Services\ShippingZoneWriter;
use EasyCo\Shipping\Persistence\Eloquent\ShippingZoneModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Create goes through ShippingZoneWriter::create(): validation, the audit entry and the hook are the service's. */
class CreateShippingZone extends CreateRecord
{
    protected static string $resource = ShippingZoneResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $zone = app(ShippingZoneWriter::class)->create(
                (string) ($data['name'] ?? ''),
                array_values((array) ($data['country_codes'] ?? [])),
                array_values((array) ($data['settlement_names'] ?? [])),
                array_values((array) ($data['postcodes'] ?? [])),
            );
        } catch (ShippingZoneInvalidException $exception) {
            ShippingZoneResource::fieldErrors($exception);
        }

        return ShippingZoneModel::findOrFail($zone->id());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('shipping.zones.notice.created');
    }

    protected function getRedirectUrl(): string
    {
        return ShippingZoneResource::getUrl('index');
    }
}
