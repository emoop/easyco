<?php

namespace App\Filament\Resources\OrderBlockResource\Pages;

use App\Filament\Resources\OrderBlockResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/** The blocks list (cod-protection-design.md 3.3). Its only header action is the manual add; the row actions are the resource's. */
class ListOrderBlocks extends ListRecords
{
    protected static string $resource = OrderBlockResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('blocks.plural');
    }

    protected function getHeaderActions(): array
    {
        return [OrderBlockResource::addAction()];
    }
}
