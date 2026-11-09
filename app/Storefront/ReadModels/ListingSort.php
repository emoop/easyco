<?php

namespace App\Storefront\ReadModels;

enum ListingSort: string
{
    case NEWEST = 'newest';
    case PRICE_ASC = 'price_asc';
    case PRICE_DESC = 'price_desc';
    case NAME_ASC = 'name_asc';

    /**
     * THE ONE list of the sorts the storefront OFFERS (a dropdown, the drawer's radios) and ACCEPTS from a request, the default first: the newest, then the price in both directions (P6a: the stored
     * `price_from_minor`). The sort by name is NOT among them any more.
     *
     * @return list<self>
     */
    public static function storefrontOptions(): array
    {
        return [self::NEWEST, self::PRICE_ASC, self::PRICE_DESC];
    }

    /**
     * The sorts a home page product grid («Продуктова решетка») can be set to in «Дизайн»: the newest or the name. NAME_ASC stays as an INTERNAL case for exactly this (the grid's own setting); a listing page
     * never offers or accepts it.
     *
     * @return list<self>
     */
    public static function homeGridOptions(): array
    {
        return [self::NEWEST, self::NAME_ASC];
    }
}
