<?php

namespace App\Filament\Resources\ShippingZoneResource\Pages;

use App\Filament\Resources\ShippingZoneResource;
use App\Filament\Support\HelpLink;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The zones in match order. No public method of its own: every write goes through a table action that calls a
 * service, and the actions are visible only with shipping_manage.
 */
class ListShippingZones extends ListRecords
{
    protected static string $resource = ShippingZoneResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('shipping.zones.title');
    }

    /** One neutral line: the first zone that matches wins; narrow zones go above broad ones — with the help link. */
    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(e(__('shipping.zones.order_note')).' '.HelpLink::html('zone_order', 'shipping')->toHtml());
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('shipping.zones.create')),
        ];
    }
}
