<?php

namespace Tests\Feature\Storefront;

use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuWriter;
use Illuminate\Support\Facades\DB;

/** Helpers for tests that need a menu: build it through the real writer (validation, one transaction, the compile) and read it back. */
trait BuildsMenus
{
    /** @param  array<string, mixed>  $extra */
    protected function menuItem(string $type, array $extra = []): array
    {
        return ['type' => $type] + $extra;
    }

    /** One column of a mega item (MENU-3): an optional heading and its links. @param  list<array<string, mixed>>  $links */
    protected function columnItem(array $links = [], ?string $heading = null): array
    {
        return ['type' => 'column', 'label' => $heading, 'children' => $links];
    }

    /** A top-level item with a mega menu of exactly `$columns` columns (the columns given are padded with empty ones). @param  list<array<string, mixed>>  $columnItems */
    protected function megaItem(string $label, string $url, int $columns, array $columnItems, array $extra = []): array
    {
        while (count($columnItems) < $columns) {
            $columnItems[] = $this->columnItem();
        }

        return $this->menuItem('link', ['label' => $label, 'url' => $url, 'submenu' => 'mega', 'mega_columns' => $columns, 'children' => $columnItems] + $extra);
    }

    /** @param  list<array<string, mixed>>  $items */
    protected function saveMenu(string $key, array $items): void
    {
        $menu = MenuModel::query()->where('key', $key)->firstOrFail();
        app(MenuWriter::class)->save($menu, $menu->name, $items);
        $this->app->forgetScopedInstances();
    }

    /** The main menu as the seed builds it: every top-level category, each a dropdown holding its direct sub-categories as explicit items. */
    protected function mainMenuOfTopCategories(): void
    {
        $items = [];

        foreach (DB::table('catalog_categories')->whereNull('parent_id')->orderBy('name')->orderBy('id')->pluck('id') as $id) {
            $sub = DB::table('catalog_categories')->where('parent_id', $id)->orderBy('name')->orderBy('id')->pluck('id')->map(fn ($subId): array => $this->menuItem('category', ['target_id' => (int) $subId]))->all();
            $items[] = $this->menuItem('category', ['target_id' => (int) $id, 'submenu' => $sub === [] ? 'none' : 'dropdown', 'children' => $sub]);
        }

        $this->saveMenu('main', $items);
    }

    /** A ready image asset (catalog_media) that is not attached to any product. */
    protected function makeMenuImage(int $id, string $status = 'ready'): int
    {
        $variants = $status === 'ready' ? [
            ['tier' => 'thumbnail', 'width' => 400, 'height' => 300, 'quality' => 80, 'path' => "m/{$id}-thumbnail.webp"],
            ['tier' => 'medium', 'width' => 900, 'height' => 675, 'quality' => 82, 'path' => "m/{$id}-medium.webp"],
        ] : [];
        DB::table('catalog_media')->insert([
            'id' => $id, 'type' => 'image', 'disk' => 'public', 'path' => "m/{$id}.jpg", 'alt_text' => null, 'processing_status' => $status,
            'variants' => json_encode($variants), 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00',
        ]);

        return $id;
    }
}
