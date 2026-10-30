<?php

namespace App\Storefront\ReadModels;

enum ListingSort: string
{
    case NEWEST = 'newest';
    case PRICE_ASC = 'price_asc';
    case PRICE_DESC = 'price_desc';
    case NAME_ASC = 'name_asc';

    /**
     * THE ONE list of the sorts the storefront OFFERS (a dropdown, the drawer's radios) and ACCEPTS from a request, the default first. The price sorts exist as cases but are not offered until the price task
     * (S7: `price_from_minor`): that task adds its two lines HERE and nowhere else.
     *
     * @return list<self>
     */
    public static function storefrontOptions(): array
    {
        return [self::NEWEST, self::NAME_ASC];
    }
}
