<?php

namespace App\Filament\Resources;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuException;
use App\Content\Menus\MenuLocations;
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
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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

/**
 * «Менюта» / Menus (D3, storefront-customization-design.md 14): the merchant's navigation as a tool of its own. A menu is an ordered tree the merchant builds (which
 * categories, in which order; a dropdown or a mega panel per top-level item); nothing is added because a category exists. Every write is ONE call to
 * App\Content\Menus\MenuWriter (validation, one transaction, the compile before the commit). Gated by `content_manage`, in the Content group next to the pages.
 * No canvas and no dragging (C-D4): up / down buttons, collapse, clone. MENU-2 (part 1) keeps this editor for the new item kinds (heading, product, badge, HTML block) and the
 * menu's locations; the WordPress-style editor replaces it in part 2.
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
            CheckboxList::make('locations')
                ->label(__('menus.fields.locations'))
                ->options(array_combine(MenuLocations::KEYS, array_map(static fn (string $location): string => __('menus.locations.'.$location), MenuLocations::KEYS)))
                ->helperText(fn (?MenuModel $record): ?string => static::movesNote($record))
                ->default([])
                ->columns(3),
        ]);
    }

    /** «Хедър е при „Футър меню“ — ще се премести»: what saving this menu with these locations takes from another menu. */
    public static function movesNote(?MenuModel $record): ?string
    {
        $notes = [];
        $names = MenuModel::query()->pluck('name', 'id')->all();

        foreach (app(MenuLocations::class)->all() as $location => $id) {
            if ($id !== null && $id !== $record?->id) {
                $notes[] = __('menus.locations_note', ['location' => __('menus.locations.'.$location), 'menu' => $names[$id] ?? '']);
            }
        }

        return $notes === [] ? null : implode(' ', $notes);
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

        if ($name === '' && $type === 'product' && ! empty($state['product_id'])) {
            $name = static::productLabel($state['product_id']) ?? '';
        }

        return trim(($type !== '' ? __('menus.types.'.$type) : '').($name !== '' ? ': '.$name : '').(($state['submenu'] ?? 'none') !== 'none' ? ' ('.__('menus.submenu.'.$state['submenu']).')' : '').(filled($state['badge_text'] ?? null) ? ' ['.$state['badge_text'].']' : ''));
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function topFields(): array
    {
        $leaf = ['link', 'category', 'page', 'product'];

        return [
            ...static::itemFields($leaf, 'category'),
            Select::make('submenu')
                ->label(__('menus.fields.submenu'))
                ->options(array_combine(MenuValidator::SUBMENUS, array_map(static fn (string $mode): string => __('menus.submenu.'.$mode), MenuValidator::SUBMENUS)))
                ->default('none')
                ->native(false)
                ->live()
                ->helperText(__('menus.help.submenu')),
            Select::make('html_block_id')
                ->label(__('menus.fields.html_block'))
                ->options(fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all())
                ->placeholder(__('menus.help.no_block'))
                ->native(false)
                ->helperText(__('menus.help.html_block'))
                ->visible(fn (Get $get): bool => $get('submenu') === 'mega'),
            Repeater::make('dropdown_items')
                ->label(__('menus.fields.dropdown_items'))
                ->visible(fn (Get $get): bool => $get('submenu') === 'dropdown')
                ->schema([
                    ...static::itemFields($leaf, 'category'),
                    Repeater::make('sub_items')
                        ->label(__('menus.fields.sub_items'))
                        ->schema(static::itemFields($leaf, 'category'))
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
                    ...static::itemFields(['heading', ...$leaf], 'heading'),
                    Repeater::make('column_items')
                        ->label(__('menus.fields.column_items'))
                        ->schema(static::itemFields($leaf, 'link'))
                        ->maxItems(MenuValidator::MAX_COLUMN)
                        ->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
                        ->itemLabel(fn (array $state): string => static::describe($state))
                        ->addActionLabel(__('menus.actions.add_item'))
                        ->default([]),
                ])
                ->maxItems(MenuValidator::MAX_COLUMNS)
                ->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
                ->itemLabel(fn (array $state): string => static::describe($state))
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
    private static function itemFields(array $types, string $defaultType): array
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
                ->helperText(fn (Get $get): ?string => in_array($get('type'), ['category', 'page', 'product'], true) ? __('menus.help.label_empty') : null)
                ->required(fn (Get $get): bool => in_array($get('type'), ['link', 'heading'], true))
                ->maxLength(120),
            TextInput::make('url')
                ->label(__('menus.fields.url'))
                ->placeholder('/ или https://')
                ->required(fn (Get $get): bool => $get('type') === 'link')
                ->maxLength(500)
                ->visible($is('link')),
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
            Select::make('product_id')
                ->label(__('menus.fields.product'))
                ->searchable()
                ->native(false)
                ->getSearchResultsUsing(fn (string $search): array => static::productOptions($search))
                ->getOptionLabelUsing(fn ($value): ?string => static::productLabel($value))
                ->required()
                ->visible($is('product')),
            TextInput::make('badge_text')
                ->label(__('menus.fields.badge_text'))
                ->maxLength(16)
                ->visible($is('link', 'category', 'page', 'product')),
            TextInput::make('badge_color')
                ->label(__('menus.fields.badge_color'))
                ->datalist(MenuValidator::BADGE_PRESETS)
                ->placeholder('red / #1a2b3c')
                ->helperText(__('menus.help.badge_color'))
                ->maxLength(7)
                ->visible($is('link', 'category', 'page', 'product')),
            Toggle::make('new_tab')->label(__('menus.fields.new_tab'))->visible($is('link', 'category', 'page', 'product')),
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
                        $item['dropdown_items'][(string) \Illuminate\Support\Str::uuid()] = ['type' => 'category', 'category_id' => (int) $id, 'submenu' => 'none', 'new_tab' => false, 'visible' => true, 'sub_items' => []];
                    }
                }

                $item['submenu'] = 'dropdown';
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

    /**
     * Products to put in a menu: matched by name or SKU (the base SKU of the product or the SKU of one of its variations), at most 20.
     *
     * @return array<int, string> id => name
     */
    public static function productOptions(string $search = ''): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return ProductModel::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('base_sku', 'like', $like)->orWhereIn('id', DB::table('catalog_variations')->where('sku', 'like', $like)->select('product_id')))
            ->orderBy('name')
            ->limit(20)
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    public static function productLabel(mixed $id): ?string
    {
        $name = ProductModel::query()->whereKey((int) $id)->value('name');

        return $name === null ? null : (string) $name;
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
