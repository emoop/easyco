<?php

namespace App\Filament\Resources\MenuResource\Pages;

use App\Content\Menus\MenuException;
use App\Content\Menus\MenuWriter;
use App\Filament\Resources\MenuResource;
use App\Filament\Support\HelpLink;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/** The menus. Creating one asks only for its key and its name; the items are built on its own page. */
class ListMenus extends ListRecords
{
    protected static string $resource = MenuResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('menus.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return HelpLink::html('appearance-menus', 'appearance');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_menu')
                ->label(__('menus.actions.create'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => MenuResource::canCreate())
                ->schema([
                    TextInput::make('name')->label(__('menus.fields.name'))->required()->maxLength(100),
                    TextInput::make('key')->label(__('menus.fields.key'))->helperText(__('menus.help.key'))->required()->maxLength(40)->regex('/^[a-z0-9-]{1,40}$/'),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $menu = app(MenuWriter::class)->create((string) $data['key'], (string) $data['name']);
                    } catch (MenuException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    $this->redirect(MenuResource::getUrl('edit', ['record' => $menu]));
                }),
        ];
    }
}
