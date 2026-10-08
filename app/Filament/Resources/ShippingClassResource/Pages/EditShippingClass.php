<?php

namespace App\Filament\Resources\ShippingClassResource\Pages;

use App\Filament\Resources\ShippingClassResource;
use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\Exceptions\ShippingClassNotFoundException;
use App\Services\ShippingClassInput;
use App\Services\ShippingClassWriter;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit goes through ShippingClassWriter::update(). The code field is read-only here and is not part of the form's
 * data, so the page names the class's own code — the service refuses any other.
 */
class EditShippingClass extends EditRecord
{
    protected static string $resource = ShippingClassResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(ShippingClassWriter::class)->update((string) $record->id, new ShippingClassInput(
                (string) ($data['name'] ?? ''),
                (string) $record->code,
                isset($data['description']) ? (string) $data['description'] : null,
            ));
        } catch (ShippingClassInvalidException $exception) {
            ShippingClassResource::fieldErrors($exception);
        } catch (ShippingClassNotFoundException $exception) {
            Notification::make()->title(__('shipping.classes.notice.refused'))->body($exception->getMessage())->danger()->send();
            $this->halt();
        }

        return $record->refresh();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('shipping.classes.notice.updated');
    }

    protected function getRedirectUrl(): ?string
    {
        return ShippingClassResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
