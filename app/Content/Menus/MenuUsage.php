<?php

namespace App\Content\Menus;

/**
 * Where a menu is used: its LOCATIONS (a menu that holds one is never deleted, the header would go blank; the message names the place) and, since DS-3, the «Дизайн» screen
 * (a `menu` field of a header element or of a footer block, published or draft — DesignMenuUsage). A menu used by either is never deleted.
 */
final class MenuUsage
{
    public function __construct(private readonly MenuLocations $locations, private readonly DesignMenuUsage $design)
    {
    }

    /** @return list<string> the places, in the admin's language (empty = no location) */
    public function places(string $key): array
    {
        $id = MenuModel::query()->where('key', $key)->value('id');

        if ($id === null) {
            return [];
        }

        return array_values(array_unique(array_map(fn (string $location): string => __('menus.locations.'.$location), $this->locations->of((int) $id))));
    }

    /** @return list<string> the parts of the design that use the menu: «Дизайн — Хедър», «Дизайн — Футър (чернова)», … (empty = none) */
    public function design(int $menuId): array
    {
        return $this->design->of($menuId);
    }
}
