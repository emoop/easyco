<?php

namespace App\Filament\Resources\HtmlBlockResource\Pages;

use App\Filament\Resources\HtmlBlockResource;
use App\Filament\Support\HelpLink;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListHtmlBlocks extends ListRecords
{
    protected static string $resource = HtmlBlockResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('html_blocks.title');
    }

    /** «Помощ: как работи →» (new tab). */
    public function getSubheading(): string|Htmlable|null
    {
        return HelpLink::html('appearance-html-blocks', 'appearance');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('html_blocks.actions.create'))];
    }
}
