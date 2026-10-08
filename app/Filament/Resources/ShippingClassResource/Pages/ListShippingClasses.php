<?php

namespace App\Filament\Resources\ShippingClassResource\Pages;

use App\Filament\Resources\ShippingClassResource;
use App\Filament\Support\HelpLink;
use Filament\Actions\CreateAction;
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
            CreateAction::make()->label(__('shipping.classes.create')),
        ];
    }
}
