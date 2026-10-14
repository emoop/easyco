<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Resources\PageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/** The pages. No write of its own: create and edit go through PageWriter. */
class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('pages.title');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('pages.actions.create'))];
    }
}
