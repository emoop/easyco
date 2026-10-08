<?php

namespace App\Filament\Resources\ShippingMethodResource\Pages;

use App\Filament\Resources\ShippingMethodResource;
use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\Exceptions\ShippingMethodNotFoundException;
use App\Services\ShippingMethodWriter;
use EasyCo\Shipping\Persistence\Eloquent\ShippingMethodModel;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Create goes through ShippingMethodWriter::create(): validation, the audit entry and the hook are the service's. */
class CreateShippingMethod extends CreateRecord
{
    protected static string $resource = ShippingMethodResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $method = app(ShippingMethodWriter::class)->create((string) ($data['zone_id'] ?? ''), ShippingMethodResource::inputFrom($data));
        } catch (ShippingMethodInvalidException $exception) {
            ShippingMethodResource::fieldErrors($exception);
        } catch (ShippingMethodNotFoundException $exception) {
            ShippingMethodResource::refused($exception->getMessage());
            $this->halt();
        }

        return ShippingMethodModel::findOrFail($method->id());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('shipping.methods.notice.created');
    }

    protected function getRedirectUrl(): string
    {
        return ShippingMethodResource::getUrl('index');
    }
}
