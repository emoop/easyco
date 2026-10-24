<?php

namespace App\Filament\Resources;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockPictures;
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
use Closure;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Storage\MediaUpload;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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
            // MENU-5: «Снимки с връзки»: each picture is a Media-domain asset with its OWN address field (a link on a picture is set here; a link on text with the editor's link button).
            Repeater::make('pictures')
                ->label(__('html_blocks.fields.pictures'))
                ->helperText(__('html_blocks.help.pictures'))
                ->maxItems(HtmlBlockPictures::MAX)
                ->defaultItems(0)
                ->addActionLabel(__('html_blocks.actions.add_picture'))
                ->reorderableWithButtons()
                ->collapsible()
                ->itemLabel(static fn (array $state): string => (string) ($state['alt'] ?? '') !== '' ? (string) $state['alt'] : __('html_blocks.fields.picture'))
                ->schema([
                    FileUpload::make('image')
                        ->label(__('html_blocks.fields.picture'))
                        ->image()
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])
                        ->maxSize(5120)
                        ->disk(config('services.media.default_disk', 'public'))
                        // The same path as a brand logo: the real bytes go through the Media storage adapter; the asset itself is made when the block is saved (picturesFromForm).
                        ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file, BaseFileUpload $component): string => MediaUpload::path($file->get(), $file->getClientOriginalName(), $component->getStatePath())),
                    Hidden::make('media_asset_id'),
                    TextInput::make('alt')->label(__('html_blocks.fields.alt'))->maxLength(HtmlBlockPictures::ALT_MAX)->rule(new PlainText()),
                    TextInput::make('url')->label(__('html_blocks.fields.url'))->placeholder('/product-category/boys')->maxLength(500)
                        ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            if (is_string($value) && trim($value) !== '' && HtmlBlockPictures::safeUrl($value) === null) {
                                $fail(__('html_blocks.errors.url'));
                            }
                        }),
                ])
                ->columns(1),
            RichEditor::make('body')
                ->label(__('html_blocks.fields.body'))
                // a block of pictures only is allowed: the body is required only when there is no picture
                ->required(static fn (Get $get): bool => ! is_array($get('pictures')) || $get('pictures') === [])
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

    /**
     * The stored pictures as the form's items: the asset's file in the upload field, its id in a hidden one (so an unchanged picture keeps its asset).
     *
     * @param  array<int, mixed>|null  $pictures
     * @return list<array{image: ?string, media_asset_id: int, alt: string, url: string}>
     */
    public static function picturesToForm(?array $pictures): array
    {
        $items = HtmlBlockPictures::clean($pictures);
        $paths = $items === [] ? [] : MediaAssetModel::query()->whereIn('id', array_column($items, 'media_asset_id'))->pluck('path', 'id')->all();

        return array_map(static fn (array $item): array => ['image' => $paths[$item['media_asset_id']] ?? null] + $item, $items);
    }

    /**
     * The form's items as the stored list: a picture whose file is the one of its asset keeps the asset; a new file becomes a NEW Media asset (the same sequence as a brand logo:
     * create, save, ProcessMediaAssetJob). The result is checked (a missing asset, a bad address or a fifth picture is a field error, nothing is saved).
     *
     * @param  array<int|string, mixed>  $items
     * @param  list<int|string>|null  $formKeys
     * @return list<array{media_asset_id: int, alt: string, url: string}>
     *
     * @throws ValidationException
     */
    public static function picturesFromForm(array $items, ?array $formKeys = null): array
    {
        // getState() numbers the items; the fields are named by the keys the browser holds ($formKeys: the page's own state), so an error lands on the field that shows it
        $keys = $formKeys !== null && count($formKeys) === count($items) ? array_values($formKeys) : array_keys($items);
        $items = array_values($items);
        $known = [];

        foreach ($items as $item) {
            $id = is_array($item) && is_numeric($item['media_asset_id'] ?? null) ? (int) $item['media_asset_id'] : null;
            $id !== null && $known[$id] = true;
        }

        $paths = $known === [] ? [] : MediaAssetModel::query()->whereIn('id', array_keys($known))->pluck('path', 'id')->all();
        $out = [];

        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];
            $path = $item['image'] ?? null;
            $path = is_array($path) ? (string) (array_values($path)[0] ?? '') : (string) $path;
            $id = is_numeric($item['media_asset_id'] ?? null) ? (int) $item['media_asset_id'] : null;

            if ($path !== '' && ($id === null || ($paths[$id] ?? null) !== $path)) {
                $id = (int) BrandResource::createLogoMediaAsset($path)->id();
            }

            $out[] = ['media_asset_id' => $id ?? 0, 'alt' => trim((string) ($item['alt'] ?? '')), 'url' => trim((string) ($item['url'] ?? ''))];
        }

        $errors = [];

        foreach (HtmlBlockPictures::errors($out) as $path => $message) {
            [$index, $field] = array_pad(explode('.', $path, 2), 2, null);
            $errors[$field === null ? 'data.pictures' : 'data.pictures.'.($keys[(int) $index] ?? $index).'.'.$field] = $message;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
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
                $refusal = HtmlBlockUsage::refusal($record);

                if ($refusal !== null) {
                    Notification::make()->title($refusal)->danger()->send();
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
