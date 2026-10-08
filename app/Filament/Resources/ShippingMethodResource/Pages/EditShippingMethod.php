<?php

namespace App\Filament\Resources\ShippingMethodResource\Pages;

use App\Filament\Resources\ShippingMethodResource;
use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\Exceptions\ShippingMethodNotFoundException;
use App\Services\MoneyInput;
use App\Services\ShippingMethodWriter;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit goes through ShippingMethodWriter::update(): it NEVER changes the zone or the order (the form has no order
 * field; the zone is read-only). A CARRIER method is not editable here (the resource refuses the page).
 *
 * Switching a method that already has class amounts from REPLACE to ADJUST asks first — "Switch to adjustments" /
 * "Back to the form" — because the same numbers then mean something else.
 */
class EditShippingMethod extends EditRecord
{
    protected static string $resource = ShippingMethodResource::class;

    /** The form edits the money as decimal text and the class amounts per class: the stored method is the source. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $method = app(ShippingMethodRepository::class)->findById((string) $this->record->id);

        return $method === null ? $data : ShippingMethodResource::formStateOf($method);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(ShippingMethodWriter::class)->update((string) $record->id, ShippingMethodResource::inputFrom($data));
        } catch (ShippingMethodInvalidException $exception) {
            ShippingMethodResource::fieldErrors($exception);
        } catch (ShippingMethodNotFoundException $exception) {
            ShippingMethodResource::refused($exception->getMessage());
            $this->halt();
        }

        return $record->refresh();
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation(fn (): bool => $this->switchesToAdjustments())
            ->modalHeading(__('shipping.methods.confirm_mode.heading'))
            ->modalDescription(__('shipping.methods.confirm_mode.description'))
            ->modalSubmitActionLabel(__('shipping.methods.confirm_mode.submit'))
            ->modalCancelActionLabel(__('shipping.methods.confirm_mode.cancel'));
    }

    /** REPLACE -> ADJUST on a method whose class amounts (as typed now) are not all zero or empty. */
    protected function switchesToAdjustments(): bool
    {
        $data = $this->data ?? [];

        if ($this->record->class_mode !== 'replace' || ($data['class_mode'] ?? null) !== 'adjust' || ($data['kind'] ?? null) !== 'per_class') {
            return false;
        }

        foreach ((array) ($data['rates'] ?? []) as $raw) {
            if (! filled($raw)) {
                continue;
            }

            $amount = ShippingMethodResource::parseSigned((string) $raw, DefaultCurrency::get());

            if ($amount === null || ! $amount->isZero()) {
                return true;
            }
        }

        return false;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('shipping.methods.notice.updated');
    }

    protected function getRedirectUrl(): ?string
    {
        return ShippingMethodResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
