<?php

namespace App\Storefront\ReadModels;

enum PerPage: int
{
    case TWELVE = 12;
    case TWENTY_FOUR = 24;
    case FORTY_EIGHT = 48;
}
