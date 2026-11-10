<?php

namespace App\Filament\Resources\PriceListResource\Pages;

use App\Filament\Resources\PriceListResource;
use App\Filament\Support\HelpLink;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/** The price lists. No public method of its own: every write goes through PriceListWriter from the create / edit pages and the status action. */
class ListPriceLists extends ListRecords
{
    protected static string $resource = PriceListResource::class;

    /** The help link (new tab): what a campaign is, the scope and the manual sale switch. */
    public function getSubheading(): string|Htmlable|null
    {
        return HelpLink::groupHtml(['price_list_overview', 'price_list_scope', 'price_list_manual_sale'], 'price_lists');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('price_lists.actions.create')),
        ];
    }
}
