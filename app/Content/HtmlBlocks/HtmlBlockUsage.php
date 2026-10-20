<?php

namespace App\Content\HtmlBlocks;

use Illuminate\Support\Facades\DB;

/** Which menus show an HTML block (a mega item names it): a block in use is never deleted, the message names the menu. */
final class HtmlBlockUsage
{
    /** @return list<string> the names of the menus that use the block (empty = free) */
    public static function menus(int $blockId): array
    {
        return DB::table('menu_items')
            ->join('menus', 'menus.id', '=', 'menu_items.menu_id')
            ->where('menu_items.html_block_id', $blockId)
            ->distinct()
            ->orderBy('menus.name')
            ->pluck('menus.name')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }
}
