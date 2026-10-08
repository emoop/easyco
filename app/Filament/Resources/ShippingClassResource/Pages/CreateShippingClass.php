<?php

namespace App\Filament\Resources\ShippingClassResource\Pages;

use App\Filament\Resources\ShippingClassResource;
use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\ShippingClassInput;
use App\Services\ShippingClassWriter;
use EasyCo\Shipping\Persistence\Eloquent\ShippingClassModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Create goes through ShippingClassWriter::create(): validation, the audit entry and the hook are the service's. */
class CreateShippingClass extends CreateRecord
{
    protected static string $resource = ShippingClassResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $class = app(ShippingClassWriter::class)->create(new ShippingClassInput(
                (string) ($data['name'] ?? ''),
                (string) ($data['code'] ?? ''),
                isset($data['description']) ? (string) $data['description'] : null,
            ));
        } catch (ShippingClassInvalidException $exception) {
            ShippingClassResource::fieldErrors($exception);
        }

        // the default class is its own write (its own audit entry and hook), made after the class exists
        if (! empty($data['is_default'])) {
            app(ShippingClassWriter::class)->setDefault((string) $class->id());
        }

        return ShippingClassModel::findOrFail($class->id());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('shipping.classes.notice.created');
    }

    protected function getRedirectUrl(): string
    {
        return ShippingClassResource::getUrl('index');
    }
}
