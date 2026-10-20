<?php

namespace App\Content\Menus;

use Illuminate\Database\Eloquent\Model;

/** One row of `menu_items` (D3). Written only by MenuWriter / MenuSeeder; the tree is rebuilt from `parent_id` and `sort` by MenuTree. */
class MenuItemModel extends Model
{
    protected $table = 'menu_items';

    protected $fillable = [
        'menu_id', 'parent_id', 'sort', 'type', 'label', 'url', 'target_id', 'submenu', 'html_block_id', 'mega_columns', 'badge_text', 'badge_color', 'new_tab', 'visible',
    ];

    protected $casts = [
        'sort' => 'integer',
        'target_id' => 'integer',
        'html_block_id' => 'integer',
        'mega_columns' => 'integer',
        'new_tab' => 'boolean',
        'visible' => 'boolean',
    ];
}
