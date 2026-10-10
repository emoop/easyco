<?php

namespace App\Storefront\ReadModels;

enum ListingScope: string
{
    case ALL = 'all';
    case CATEGORY = 'category';
    case BRAND = 'brand';
    case TAG = 'tag';
}
