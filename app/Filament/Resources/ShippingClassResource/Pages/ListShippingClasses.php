<?php

namespace App\Filament\Resources\ShippingClassResource\Pages;

use App\Filament\Resources\ShippingClassResource;
use App\Filament\Support\HelpLink;
use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\ShippingClassWriter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The shipping classes. No public method of its own: every write goes through a table action that calls a service,
 * and the actions are visible only with shipping_manage.
 */
class ListShippingClasses extends ListRecords
{
    protected static string $resource = ShippingClassResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('shipping.classes.title');
    }

    /** The help link (new tab): what a class is, and why one cannot be deleted while it is used. */
    public function getSubheading(): string|Htmlable|null
    {
        return HelpLink::html('classes', 'shipping');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Only while the store has no class at all: one step to the first, default class (what the
            // assign-missing command offers with --create-default).
            Action::make('create_default_class')
                ->label(__('shipping.classes.actions.create_default'))
                ->requiresConfirmation()
                ->modalHeading(__('shipping.classes.create_default.heading'))
                ->modalDescription(__('shipping.classes.create_default.description'))
                ->modalSubmitActionLabel(__('shipping.classes.create_default.submit'))
                ->modalCancelActionLabel(__('orders.modal.close'))
                ->visible(fn (): bool => $this->getTableRecords()->total() === 0)
                ->action(function (): void {
                    try {
                        app(ShippingClassWriter::class)->createDefault();
                    } catch (ShippingClassInvalidException $exception) {
                        Notification::make()->title(__('shipping.classes.notice.refused'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('shipping.classes.notice.default_created'))->success()->send();
                }),
            CreateAction::make()->label(__('shipping.classes.create')),
        ];
    }
}
