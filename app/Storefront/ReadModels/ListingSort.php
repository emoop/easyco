<?php

namespace App\Storefront\ReadModels;

enum ListingSort: string
{
    case NEWEST = 'newest';
    case PRICE_ASC = 'price_asc';
    case PRICE_DESC = 'price_desc';
    case NAME_ASC = 'name_asc';
}
