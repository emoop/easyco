<?php

namespace App\Filament\Resources;

use App\Content\Menus\MenuException;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuValidator;
use App\Content\Menus\MenuWriter;
use App\Content\Models\PageModel;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\MenuResource\Pages\EditMenu;
use App\Filament\Resources\MenuResource\Pages\ListMenus;
use App\Filament\Support\HelpLink;
use BackedEnum;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * «Менюта» / Menus (D3, storefront-customization-design.md 14): the merchant's navigation as a tool of its own. A menu is an ordered tree the merchant builds (which
 * categories, in which order; a dropdown or a mega panel per top-level item); nothing is added because a category exists. Every write is ONE call to
 * App\Content\Menus\MenuWriter (validation, one transaction, the compile before the commit). Gated by `content_manage`, in the Content group next to the pages.
 * No canvas and no dragging (C-D4): up / down buttons, collapse, clone.
 */
class MenuResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = MenuModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    /** @var array<int, array{name: string, parent: ?int}>|null */
    private static ?array $categories = null;

    public static function getModelLabel(): string
    {
        return __('menus.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('menus.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('menus.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CONTENT;
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
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

    // ---- the list -------------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->modifyQueryUsing(fn ($query) => $query->withCount('items'))
            ->columns([
                TextColumn::make('name')->label(__('menus.fields.name'))->weight('bold'),
                TextColumn::make('key')->label(__('menus.fields.key'))->badge()->color('gray'),
                TextColumn::make('items_count')->label(__('menus.fields.items_count')),
            ])
            ->recordActions([
                Action::make('edit_menu')
                    ->label(__('menus.actions.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (MenuModel $record): string => static::getUrl('edit', ['record' => $record]))
                    ->visible(fn (MenuModel $record): bool => static::canEdit($record)),
                Action::make('delete_menu')
                    ->label(__('menus.actions.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (MenuModel $record): bool => ! $record->isProtected() && static::canDelete($record))
                    ->action(function (MenuModel $record): void {
                        try {
                            app(MenuWriter::class)->delete($record);
                        } catch (MenuException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('menus.notice.deleted'))->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenus::route('/'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }

    // ---- the form -------------------------------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label(__('menus.fields.name'))->required()->maxLength(100),
            HelpLink::component('appearance-menus', 'appearance'),
            Text::make(fn (Get $get, ?MenuModel $record): string => $record?->key === 'main' ? (static::warning((array) ($get('items') ?? [])) ?? '') : '')
                ->color('warning')
                ->visible(fn (Get $get, ?MenuModel $record): bool => $record?->key === 'main' && static::warning((array) ($get('items') ?? [])) !== null),
            Repeater::make('items')
                ->label(__('menus.fields.items'))
                ->schema(static::topFields())
                ->maxItems(MenuValidator::MAX_TOP)
                ->collapsible()
                ->collapsed()
                ->cloneable()
                
                ->reorderableWithButtons()
                ->itemLabel(fn (array $state): string => static::describe($state))
                ->addActionLabel(__('menus.actions.add_item'))
                ->extraItemActions([static::addChildrenAction()])
                ->default([]),
        ]);
    }

    /** The warning, not a refusal, for the main menu: more than 7 top-level items or a label over 20 characters. @param  array<mixed>  $items */
    public static function warning(array $items): ?string
    {
        $long = false;

        foreach ($items as $item) {
            $label = (string) ($item['label'] ?? '');
            $long = $long || mb_strlen($label) > 20;
        }

        return count($items) > 7 || $long ? __('menus.warning_crowded') : null;
    }

    /** What an item says in the collapsed list. @param  array<string, mixed>  $state */
    public static function describe(array $state): string
    {
        $type = (string) ($state['type'] ?? '');
        $name = trim((string) ($state['label'] ?? ''));

        if ($name === '' && $type === 'category' && ! empty($state['category_id'])) {
            $name = static::categoryLabel($state['category_id']) ?? '';
        }

        if ($name === '' && $type === 'page' && ! empty($state['page_id'])) {
            $name = static::pageOptions()[(int) $state['page_id']] ?? '';
        }

        return trim(($type !== '' ? __('menus.types.'.$type) : '').($name !== '' ? ': '.$name : '').(($state['submenu'] ?? 'none') !== 'none' ? ' ('.__('menus.submenu.'.$state['submenu']).')' : ''));
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function topFields(): array
    {
        return [
            ...static::itemFields(['link', 'category', 'page'], 'category'),
            Select::make('submenu')
                ->label(__('menus.fields.submenu'))
                ->options(array_combine(MenuValidator::SUBMENUS, array_map(static fn (string $mode): string => __('menus.submenu.'.$mode), MenuValidator::SUBMENUS)))
                ->default('none')
                ->native(false)
                ->live()
                ->helperText(__('menus.help.submenu')),
            Repeater::make('dropdown_items')
                ->label(__('menus.fields.dropdown_items'))
                ->visible(fn (Get $get): bool => $get('submenu') === 'dropdown')
                ->schema([
                    ...static::itemFields(['link', 'category', 'page'], 'category', withChildrenToggle: true),
                    Repeater::make('sub_items')
                        ->label(__('menus.fields.sub_items'))
                        ->schema(static::itemFields(['link', 'category', 'page'], 'category', withChildrenToggle: true))
                        ->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
                        ->itemLabel(fn (array $state): string => static::describe($state))
                        ->addActionLabel(__('menus.actions.add_item'))
                        ->default([]),
                ])
                ->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
                ->itemLabel(fn (array $state): string => static::describe($state))
                ->addActionLabel(__('menus.actions.add_item'))
                ->default([]),
            Repeater::make('columns')
                ->label(__('menus.fields.columns'))
                ->visible(fn (Get $get): bool => $get('submenu') === 'mega')
                ->schema([
                    TextInput::make('label')->label(__('menus.fields.column_heading'))->maxLength(120),
                    Toggle::make('visible')->label(__('menus.fields.visible'))->default(true),
                    Repeater::make('column_items')
                        ->label(__('menus.fields.column_items'))
                        ->schema(static::itemFields(['link', 'category', 'page', 'image'], 'link', withChildrenToggle: true))
                        ->maxItems(MenuValidator::MAX_COLUMN)
                        ->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
                        ->itemLabel(fn (array $state): string => static::describe($state))
                        ->addActionLabel(__('menus.actions.add_item'))
                        ->default([]),
                ])
                ->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
                ->itemLabel(fn (array $state): string => ($state['label'] ?? '') !== '' ? (string) $state['label'] : __('menus.types.column'))
                ->addActionLabel(__('menus.actions.add_column'))
                ->default([]),
        ];
    }

    /**
     * The fields every item has: its type first, then only the fields of that type.
     *
     * @param  list<string>  $types
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function itemFields(array $types, string $defaultType, bool $withChildrenToggle = false): array
    {
        $is = static fn (string ...$of): \Closure => static fn (Get $get): bool => in_array($get('type'), $of, true);

        return [
            Select::make('type')
                ->label(__('menus.fields.type'))
                ->options(array_combine($types, array_map(static fn (string $type): string => __('menus.types.'.$type), $types)))
                ->default($defaultType)
                ->native(false)
                ->required()
                ->live(),
            TextInput::make('label')
                ->label(__('menus.fields.label'))
                ->helperText(fn (Get $get): ?string => in_array($get('type'), ['category', 'page'], true) ? __('menus.help.label_empty') : null)
                ->required(fn (Get $get): bool => $get('type') === 'link')
                ->maxLength(120)
                ->visible(fn (Get $get): bool => $get('type') !== 'image'),
            TextInput::make('url')
                ->label(fn (Get $get): string => $get('type') === 'image' ? __('menus.fields.image_link') : __('menus.fields.url'))
                ->placeholder('/ или https://')
                ->required(fn (Get $get): bool => $get('type') === 'link')
                ->maxLength(500)
                ->visible($is('link', 'image')),
            Select::make('category_id')
                ->label(__('menus.fields.category'))
                ->searchable()
                ->native(false)
                ->getSearchResultsUsing(fn (string $search): array => static::categoryOptions($search))
                ->getOptionLabelUsing(fn ($value): ?string => static::categoryLabel($value))
                ->required()
                ->visible($is('category')),
            Select::make('page_id')
                ->label(__('menus.fields.page'))
                ->native(false)
                ->options(fn (): array => static::pageOptions())
                ->required()
                ->visible($is('page')),
            Toggle::make('with_children')
                ->label(__('menus.fields.with_children'))
                ->helperText(__('menus.help.with_children'))
                ->live()
                ->visible($is('category')),
            TextInput::make('children_limit')
                ->label(__('menus.fields.children_limit'))
                ->numeric()->integer()->minValue(1)->maxValue(50)->default(12)
                ->visible(fn (Get $get): bool => $get('type') === 'category' && (bool) $get('with_children')),
            Hidden::make('media_asset_id'),
            FileUpload::make('image_file')
                ->label(__('menus.fields.image'))
                ->image()
                ->disk(config('services.media.default_disk', 'public'))
                ->helperText(__('menus.help.image'))
                // The same route every other upload takes (EasyCo\Media\Contracts\MediaStorageAdapter): the asset is made when the menu is saved.
                ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(MediaStorageAdapter::class)->store($file->get(), $file->getClientOriginalName())->path)
                ->required(fn (Get $get): bool => $get('type') === 'image' && blank($get('media_asset_id')))
                ->visible($is('image')),
            TextInput::make('image_alt')->label(__('menus.fields.image_alt'))->maxLength(200)->visible($is('image')),
            TextInput::make('caption')->label(__('menus.fields.caption'))->maxLength(80)->visible($is('image')),
            Toggle::make('new_tab')->label(__('menus.fields.new_tab')),
            Toggle::make('visible')->label(__('menus.fields.visible'))->default(true),
        ];
    }

    /** «Добави подкатегориите»: on a top-level category item, its sub-categories as explicit dropdown items (the merchant can then remove the ones to hide). */
    private static function addChildrenAction(): Action
    {
        return Action::make('add_subcategories')
            ->label(__('menus.actions.add_subcategories'))
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (array $arguments, Repeater $component): bool => (($component->getRawState()[$arguments['item'] ?? ''] ?? [])['type'] ?? null) === 'category')
            ->action(function (array $arguments, Repeater $component): void {
                $state = $component->getRawState();
                $key = $arguments['item'] ?? '';
                $item = $state[$key] ?? null;

                if (! is_array($item) || ($item['type'] ?? null) !== 'category' || empty($item['category_id'])) {
                    return;
                }

                $present = [];

                foreach ((array) ($item['dropdown_items'] ?? []) as $existing) {
                    $present[(int) ($existing['category_id'] ?? 0)] = true;
                }

                $rows = DB::table('catalog_categories')->where('parent_id', (int) $item['category_id'])->orderBy('name')->orderBy('id')->pluck('id');

                foreach ($rows as $id) {
                    if (! isset($present[(int) $id])) {
                        $item['dropdown_items'][(string) \Illuminate\Support\Str::uuid()] = ['type' => 'category', 'category_id' => (int) $id, 'submenu' => 'none', 'with_children' => false, 'children_limit' => 12, 'new_tab' => false, 'visible' => true, 'sub_items' => []];
                    }
                }

                $item['submenu'] = 'dropdown';
                $item['with_children'] = false;
                $state[$key] = $item;
                $component->rawState($state);
            });
    }

    // ---- options ------------------------------------------------------------------------------------------------------

    /** @return array<int, array{name: string, parent: ?int}> */
    private static function categories(): array
    {
        if (self::$categories === null) {
            self::$categories = [];

            foreach (DB::table('catalog_categories')->orderBy('name')->orderBy('id')->get(['id', 'parent_id', 'name']) as $row) {
                self::$categories[(int) $row->id] = ['name' => (string) $row->name, 'parent' => $row->parent_id === null ? null : (int) $row->parent_id];
            }
        }

        return self::$categories;
    }

    /** «Дрехи › Ризи»: the category with its path. */
    public static function categoryLabel(mixed $id): ?string
    {
        $categories = static::categories();
        $id = (int) $id;

        if (! isset($categories[$id])) {
            return null;
        }

        $names = [];
        $guard = 0;

        for ($current = $id; $current !== null && isset($categories[$current]) && $guard++ < 10; $current = $categories[$current]['parent']) {
            array_unshift($names, $categories[$current]['name']);
        }

        return implode(' › ', $names);
    }

    /** @return array<int, string> id => path label, matching the search, at most 50 */
    public static function categoryOptions(string $search = ''): array
    {
        $options = [];

        foreach (array_keys(static::categories()) as $id) {
            $label = (string) static::categoryLabel($id);

            if ($search === '' || mb_stripos($label, $search) !== false) {
                $options[$id] = $label;
            }

            if (count($options) >= 50) {
                break;
            }
        }

        return $options;
    }

    /** @return array<int, string> the published pages: id => title */
    public static function pageOptions(): array
    {
        return PageModel::query()->where('status', PageModel::PUBLISHED)->orderBy('title')->pluck('title', 'id')->all();
    }

    /** Forgets the memoised categories (tests, and after a helper action added some). */
    public static function forgetMemo(): void
    {
        self::$categories = null;
    }
}
