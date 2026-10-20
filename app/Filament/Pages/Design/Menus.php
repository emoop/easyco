<?php

namespace App\Filament\Pages\Design;

use App\Content\Menus\MenuEditorData;
use App\Content\Menus\MenuException;
use App\Content\Menus\MenuFlat;
use App\Content\Menus\MenuLocations;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuWriter;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Services\ActivityLogger;
use App\Storefront\Menu\MenuCompileException;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * «Менюта» / Menus (MENU-2, menu-editor-v2-design.md): ONE screen in the WordPress layout. The structure is held in the browser as a flat list with a depth (Alpine + SortableJS,
 * resources/js/admin-menu-editor.js) so dragging and editing cost no round trip; «Запази менюто» sends the whole list in ONE call to saveMenu(), which derives the parents from the
 * depths, has MenuWriter validate and write it all-or-nothing (items, name and locations in one transaction, compile before the commit) and answers either success or the errors
 * by item index. The page also serves the product search and the menu list. Gated by `content_manage`; every public method checks it again (a Livewire call does not pass through
 * the route's check).
 */
class Menus extends Page
{
    use AuthorizesViaStaffPermission;

    protected static ?string $slug = 'menus';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected string $view = 'filament.pages.design.menus';

    /** The menu shown (the `menu` query string, else the first); only a start value, the browser holds the rest. */
    public ?int $menuId = null;

    public function getTitle(): string
    {
        return __('menus.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('menus.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::DESIGN;
    }

    public static function getNavigationSort(): ?int
    {
        return 30;
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    private function authorizeEditing(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    // ---- what the view needs ------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the whole starting state of the editor (one JSON object for Alpine) */
    public function editorState(): array
    {
        $data = app(MenuEditorData::class);
        $menus = $data->menus();
        $current = MenuModel::query()->find($this->menuId ?? request()->integer('menu')) ?? MenuModel::query()->orderBy('id')->first();

        return [
            'menus' => $menus,
            'menu' => $current === null ? null : $data->menu($current),
            'categories' => $data->categories(),
            'pages' => $data->pages(),
            'blocks' => $data->blocks(),
            'locations' => array_map(fn (string $location): array => ['key' => $location, 'label' => __('menus.locations.'.$location)], MenuLocations::KEYS),
            'holders' => $this->holders($menus),
            'presets' => \App\Content\Menus\MenuValidator::BADGE_PRESETS,
            'limits' => ['top' => \App\Content\Menus\MenuValidator::MAX_TOP, 'columns' => \App\Content\Menus\MenuValidator::MAX_COLUMNS, 'column' => \App\Content\Menus\MenuValidator::MAX_COLUMN, 'total' => \App\Content\Menus\MenuValidator::MAX_TOTAL],
            'i18n' => __('menus.editor'),
            'urls' => ['blocks' => \App\Filament\Resources\HtmlBlockResource::getUrl('create')],
        ];
    }

    public function helpLink(): \Illuminate\Support\HtmlString
    {
        return HelpLink::html('appearance-menus', 'appearance');
    }

    /**
     * Which menu holds each location now (for the note «премества се от …»).
     *
     * @param  list<array{id: int, name: string}>  $menus
     * @return array<string, ?array{id: int, name: string}>
     */
    private function holders(array $menus): array
    {
        $names = array_column($menus, 'name', 'id');
        $out = [];

        foreach (app(MenuLocations::class)->all() as $location => $id) {
            $out[$location] = $id === null ? null : ['id' => $id, 'name' => (string) ($names[$id] ?? '')];
        }

        return $out;
    }

    // ---- the calls of the editor ----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function loadMenu(int $id): array
    {
        $this->authorizeEditing();
        $menu = MenuModel::query()->find($id);

        return $menu === null
            ? ['ok' => false, 'message' => __('menus.errors.gone')]
            : ['ok' => true, 'menu' => app(MenuEditorData::class)->menu($menu), 'holders' => $this->holders(app(MenuEditorData::class)->menus())];
    }

    /** @return array<string, mixed> */
    public function createMenu(string $name): array
    {
        $this->authorizeEditing();
        $name = trim($name);
        $base = Str::slug($name, '-', 'bg');
        $base = $base === '' ? 'menu' : mb_substr($base, 0, 34);
        $key = $base;

        for ($n = 2; MenuModel::query()->where('key', $key)->exists() && $n < 100; $n++) {
            $key = $base.'-'.$n;
        }

        try {
            $menu = app(MenuWriter::class)->create($key, $name);
        } catch (MenuException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        $data = app(MenuEditorData::class);

        return ['ok' => true, 'menus' => $data->menus(), 'menu' => $data->menu($menu), 'holders' => $this->holders($data->menus())];
    }

    /**
     * ONE save: the flat list, the name and the locations. Nothing is written unless everything is valid.
     *
     * @param  array<mixed>  $items
     * @param  array<mixed>  $locations
     * @return array<string, mixed> `ok` + the saved menu, or `ok` false + the errors by item index (`items`) and for the menu itself (`menu`)
     */
    public function saveMenu(int $id, string $name, array $items, array $locations): array
    {
        $this->authorizeEditing();
        $menu = MenuModel::query()->find($id);

        if ($menu === null) {
            return ['ok' => false, 'message' => __('menus.errors.gone'), 'items' => [], 'menu' => []];
        }

        [$tree, $map, $depthErrors] = MenuFlat::toTree($items);

        if ($depthErrors !== []) {
            return $this->refused(array_map(fn (string $message): array => ['_item' => $message], $depthErrors), []);
        }

        $locations = array_values(array_filter($locations, 'is_string'));

        try {
            $moved = app(MenuWriter::class)->save($menu, $name, $tree, $locations);
        } catch (MenuException $exception) {
            $byItem = MenuFlat::errorsByItem($exception->errors, $map);

            return $this->refused($byItem['items'], $byItem['menu'] === [] && $byItem['items'] === [] ? ['items' => $exception->getMessage()] : $byItem['menu']);
        } catch (MenuCompileException $exception) {
            return $this->refused([], ['items' => $exception->getMessage()]);
        }

        // Who saved which menu; never a label or a link.
        app(ActivityLogger::class)->logFieldChanged('storefront_menu', $menu->key, 'items', null, 'saved');

        $data = app(MenuEditorData::class);
        $note = [];

        foreach ($moved as $location => $from) {
            $note[] = __('menus.notice.moved', ['location' => __('menus.locations.'.$location), 'menu' => $from]);
        }

        Notification::make()->title(__('menus.notice.saved'))->body($note === [] ? null : implode(' ', $note))->success()->send();

        return ['ok' => true, 'menus' => $data->menus(), 'menu' => $data->menu($menu->refresh()), 'holders' => $this->holders($data->menus()), 'moved' => $note];
    }

    /** @return array<string, mixed> */
    public function deleteMenu(int $id): array
    {
        $this->authorizeEditing();
        $menu = MenuModel::query()->find($id);

        if ($menu === null) {
            return ['ok' => false, 'message' => __('menus.errors.gone')];
        }

        try {
            app(MenuWriter::class)->delete($menu);
        } catch (MenuException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        Notification::make()->title(__('menus.notice.deleted'))->success()->send();
        $data = app(MenuEditorData::class);
        $next = MenuModel::query()->orderBy('id')->first();

        return ['ok' => true, 'menus' => $data->menus(), 'menu' => $next === null ? null : $data->menu($next), 'holders' => $this->holders($data->menus())];
    }

    /** @return list<array{id: int, name: string, sku: ?string, url: string}> */
    public function searchProducts(string $query): array
    {
        $this->authorizeEditing();

        return app(MenuEditorData::class)->products($query);
    }

    /**
     * @param  array<int, array<string, string>>  $items
     * @param  array<string, string>  $menu
     * @return array<string, mixed>
     */
    private function refused(array $items, array $menu): array
    {
        return ['ok' => false, 'message' => __('menus.errors.invalid'), 'items' => $items, 'menu' => $menu];
    }
}
