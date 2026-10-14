<?php

namespace App\Filament\Resources\OrderBlockResource\Pages;

use App\Filament\Resources\OrderBlockResource;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Pages\Help;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/** The blocks list (cod-protection-design.md 3.3). Its only header action is the manual add; the row actions are the resource's. */
class ListOrderBlocks extends ListRecords
{
    protected static string $resource = OrderBlockResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('blocks.plural');
    }

    /**
     * «Помощ: как работи →» (new tab) to the section "Protection against fake cash-on-delivery orders" (`#cod-protection`). That anchor is not an
     * `action-…` one, so HelpLink (which prefixes every anchor with `action-`) cannot name it: the same `<a>` is built here, with the same label
     * and attributes, from the same URL helper.
     */
    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('<a href="'.e(Help::getUrl(['topic' => 'orders']).'#cod-protection').'" target="_blank" rel="noopener noreferrer" class="help-link" style="text-decoration: underline;">'.e(__('help.link')).'</a>');
    }

    protected function getHeaderActions(): array
    {
        return [OrderBlockResource::addAction()];
    }
}
