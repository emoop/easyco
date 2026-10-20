<?php

namespace App\Filament\Resources;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\ListHtmlBlocks;
use App\Rules\PlainText;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * «HTML блокове» (MENU-2, menu-editor-v2-design.md 2): small reusable pieces of rich content (the picture next to a mega menu; design slots later). The editor offers exactly
 * what HtmlBlockSanitizer keeps (H2-H4, bold, italic, lists, a link, a picture); the body is cleaned on save by the model and again when it is printed. Gated by `content_manage`.
 * A block a menu shows cannot be deleted: the message names the menu.
 */
class HtmlBlockResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = HtmlBlockModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket-square';

    public static function getModelLabel(): string
    {
        return __('html_blocks.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('html_blocks.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('html_blocks.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::DESIGN;
    }

    public static function getNavigationSort(): ?int
    {
        return 40;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    protected static function viewPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    protected static function deletePermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('title')->label(__('html_blocks.fields.title'))->required()->maxLength(120)->rule(new PlainText()),
            RichEditor::make('body')
                ->label(__('html_blocks.fields.body'))
                ->required()
                ->toolbarButtons([
                    ['bold', 'italic', 'link'],
                    ['h2', 'h3', 'h4'],
                    ['bulletList', 'orderedList'],
                    ['attachFiles'],
                    ['undo', 'redo'],
                ])
                ->fileAttachmentsDisk('public')
                ->fileAttachmentsDirectory('html-blocks')
                ->fileAttachmentsAcceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])
                ->fileAttachmentsMaxSize(5120)
                ->helperText(__('html_blocks.help.body')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label(__('html_blocks.fields.title'))->weight('bold')->searchable(),
                TextColumn::make('updated_at')->label(__('html_blocks.fields.updated_at'))->dateTime('d.m.Y H:i'),
            ])
            ->recordActions([
                EditAction::make()->label(__('html_blocks.actions.edit')),
                static::deleteAction(),
            ]);
    }

    /** The delete button of the list and of the edit page: refused, with the menu named, while a menu shows the block. */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label(__('html_blocks.actions.delete'))
            ->before(function (DeleteAction $action, HtmlBlockModel $record): void {
                $menus = HtmlBlockUsage::menus((int) $record->id);

                if ($menus !== []) {
                    Notification::make()->title(__('html_blocks.errors.in_use', ['title' => $record->title, 'menus' => implode(', ', $menus)]))->danger()->send();
                    $action->halt();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHtmlBlocks::route('/'),
            'create' => CreateHtmlBlock::route('/create'),
            'edit' => EditHtmlBlock::route('/{record}/edit'),
        ];
    }
}
