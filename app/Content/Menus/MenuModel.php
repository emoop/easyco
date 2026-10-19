<?php

namespace App\Content\Menus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A menu (D3): `key` is what the header's menu element and the footer refer to. Every write of a menu's items goes through MenuWriter.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 */
class MenuModel extends Model
{
    /** The two menus that are seeded and can never be deleted. */
    public const PROTECTED_KEYS = ['main', 'footer'];

    protected $table = 'menus';

    protected $fillable = ['key', 'name'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItemModel::class, 'menu_id')->orderBy('sort')->orderBy('id');
    }

    public function isProtected(): bool
    {
        return in_array($this->key, self::PROTECTED_KEYS, true);
    }
}
