<?php

namespace App\Storefront\ReadModels;

enum PerPage: int
{
    case TWELVE = 12;
    case TWENTY_FOUR = 24;
    case THIRTY_SIX = 36;
    case FORTY_EIGHT = 48;
    case SIXTY = 60;
    case SEVENTY_TWO = 72;
}
