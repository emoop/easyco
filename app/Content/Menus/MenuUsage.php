<?php

namespace App\Content\Menus;

use App\Storefront\Header\HeaderMenuUsage;

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

        $places = array_map(fn (string $location): string => __('menus.locations.'.$location), $this->locations->of((int) $id));

        // DS-2a.1: a menu a `menu` element of the published OR the draft header names explicitly is used too (the header would lose it)
        if (HeaderMenuUsage::uses((int) $id)) {
            $places[] = __('menus.places.header_element');
        }

        return array_values(array_unique($places));
    }
}
