<?php

namespace App\Filament\Resources\ShippingMethodResource\Pages;

use App\Filament\Resources\ShippingMethodResource;
use App\Filament\Support\HelpLink;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Every zone's methods, zones in match order, then the method order inside each zone. No public method of its own:
 * every write goes through a table action or toggle that calls a service, visible only with shipping_manage.
 */
class ListShippingMethods extends ListRecords
{
    protected static string $resource = ShippingMethodResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('shipping.methods.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(e(__('shipping.methods.order_note')).' '.HelpLink::html('method_editor', 'shipping')->toHtml());
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('shipping.methods.create')),
        ];
    }
}
