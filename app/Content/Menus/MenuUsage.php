<?php

namespace App\Content\Menus;

/** Where a menu sits in the shop (its locations): a menu that holds a location is never deleted (the header would go blank), the message names the place. */
final class MenuUsage
{
    public function __construct(private readonly MenuLocations $locations)
    {
    }

    /** @return list<string> the places, in the admin's language (empty = not used) */
    public function places(string $key): array
    {
        $id = MenuModel::query()->where('key', $key)->value('id');

        if ($id === null) {
            return [];
        }

        return array_map(fn (string $location): string => __('menus.locations.'.$location), $this->locations->of((int) $id));
    }
}
