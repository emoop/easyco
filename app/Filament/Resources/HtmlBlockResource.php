<?php

namespace App\Filament\Resources;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockPictures;
use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Forms\Components\MediaImagePlugin;
use App\Filament\Forms\Components\MediaPicker;
use App\Filament\NavigationGroup;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\ListHtmlBlocks;
use App\Rules\PlainText;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use App\Settings\AppearanceSettings;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Closure;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
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
                    // ML-2: the picture is picked from the media library or uploaded into it; the block keeps only its id (the stored shape {media_asset_id, alt, url} is unchanged)
                    MediaPicker::make('media_asset_id')
                        ->label(__('html_blocks.fields.picture'))
                        ->required()
                        ->afterStateUpdated(static function (mixed $state, Set $set, Get $get): void {
                            // a picked picture brings its own alt text when the block has none yet
                            if (filled($state) && blank($get('alt'))) {
                                $alt = (string) MediaAssetModel::query()->whereKey((int) $state)->value('alt_text');
                                $alt !== '' && $set('alt', mb_substr($alt, 0, HtmlBlockPictures::ALT_MAX));
                            }
                        }),
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
                    [MediaImagePlugin::TOOL],
                    ['undo', 'redo'],
                ])
                // ML-2: the picture button opens the media picker (pick or upload into the library) instead of saving loose files in public/html-blocks
                ->plugins([MediaImagePlugin::make()])
                ->helperText(__('html_blocks.help.body')),
            self::styleSection(),
        ]);
    }

    /** HB-1: «Стил на блока»: the fields, the live preview, the contrast warning and «Без стил». The values are validated by HtmlBlockStyle on save (styleFromForm). */
    private static function styleSection(): Section
    {
        $number = static fn (string $key): TextInput => TextInput::make('style.'.$key)
            ->label(__('html_blocks.style.fields.'.$key))
            ->helperText(__('html_blocks.style.hints.'.$key))
            // NOT `numeric()`: Filament casts it through floatval (GL-2); the validator must see the text as it was typed
            ->inputMode('numeric')->maxLength(3)->suffix('px')->live(onBlur: true);
        $color = static fn (string $key): ColorPicker => ColorPicker::make('style.'.$key)
            ->label(__('html_blocks.style.fields.'.$key))
            ->helperText(__('html_blocks.style.hints.'.$key))
            ->hex()->live(onBlur: true)
            ->hintAction(Action::make('clear_'.$key)->label(__('html_blocks.style.none'))->action(static fn (Set $set) => $set('style.'.$key, null)));
        $options = static fn (string $key, array $values): Select => Select::make('style.'.$key)
            ->label(__('html_blocks.style.fields.'.$key))
            ->helperText(__('html_blocks.style.hints.'.$key))
            ->native(false)->live()
            ->options(array_combine($values, array_map(static fn (string $v): string => (string) __('html_blocks.style.options.'.$key.'.'.$v), $values)));

        return Section::make(__('html_blocks.style.title'))
            ->description(__('html_blocks.style.description'))
            ->collapsible()
            ->columns(2)
            ->schema([
                $color('bg'),
                $color('color'),
                $number('pad_v'),
                $number('pad_h'),
                $number('border_w'),
                $color('border_color'),
                $number('radius'),
                $options('width_mode', HtmlBlockStyle::WIDTH_MODES),
                $number('width_px')->visible(static fn (Get $get): bool => $get('style.width_mode') === 'fixed'),
                $options('align', HtmlBlockStyle::ALIGNS),
                $options('size', HtmlBlockStyle::SIZES),
                Text::make(static fn (Get $get): HtmlString => new HtmlString(view('filament.resources.html-block.style-preview', [
                    'declarations' => HtmlBlockStyle::previewDeclarations($get('style')),
                    'sample' => (string) ($get('title') ?: __('html_blocks.style.sample')),
                ])->render()))->columnSpanFull(),
                Text::make(static fn (Get $get): string => (string) __('html_blocks.style.contrast_warning', ['ratio' => number_format(AppearanceSettings::contrastRatio((string) $get('style.color'), (string) $get('style.bg')), 2)]))
                    ->color('warning')->size('sm')->columnSpanFull()
                    ->visible(static fn (Get $get): bool => AppearanceSettings::isHex($get('style.color')) && AppearanceSettings::isHex($get('style.bg'))
                        && AppearanceSettings::contrastRatio((string) $get('style.color'), (string) $get('style.bg')) < AppearanceSettings::MIN_CONTRAST),
                Actions::make([
                    Action::make('clear_style')->label(__('html_blocks.style.clear'))->color('gray')->action(static fn (Set $set) => $set('style', HtmlBlockStyle::formState(null))),
                ])->key('style-actions')->columnSpanFull(),
            ]);
    }

    /**
     * The form's style as the stored one: validated by HtmlBlockStyle (a refusal is a field error under the field, nothing is saved), and the 101st styled block is refused.
     *
     * @return array<string, mixed>|null null = no style
     *
     * @throws ValidationException
     */
    public static function styleFromForm(mixed $style, ?int $recordId): ?array
    {
        [$clean, $errors] = HtmlBlockStyle::validate($style);

        if ($errors === [] && HtmlBlockStyle::isSet($clean) && HtmlBlockStyle::capReached($recordId)) {
            $errors = ['bg' => (string) __('html_blocks.style.errors.too_many', ['max' => HtmlBlockStyle::MAX_STYLED])];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_combine(array_map(static fn (string $key): string => 'data.style.'.($key === 'style' ? 'bg' : $key), array_keys($errors)), array_values($errors)));
        }

        return HtmlBlockStyle::storable($clean);
    }

    /**
     * The stored pictures as the form's items (ML-2: the id is the whole picture; the picker shows the file).
     *
     * @param  array<int, mixed>|null  $pictures
     * @return list<array{media_asset_id: int, alt: string, url: string}>
     */
    public static function picturesToForm(?array $pictures): array
    {
        return HtmlBlockPictures::clean($pictures);
    }

    /**
     * The form's items as the stored list. The ids come from the picker and are checked again here (a missing asset, a bad address or a fifth picture is a field error, nothing is saved).
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
        $out = [];

        foreach (array_values($items) as $item) {
            $item = is_array($item) ? $item : [];
            $id = is_numeric($item['media_asset_id'] ?? null) ? (int) $item['media_asset_id'] : 0;

            $out[] = ['media_asset_id' => $id, 'alt' => trim((string) ($item['alt'] ?? '')), 'url' => trim((string) ($item['url'] ?? ''))];
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
