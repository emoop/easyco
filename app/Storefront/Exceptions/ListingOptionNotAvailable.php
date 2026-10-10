<?php

namespace App\Storefront\Exceptions;

use RuntimeException;

/** A valid ListingQuery option that this stage cannot honour yet (price filter/sort need S7's price_from_minor). */
final class ListingOptionNotAvailable extends RuntimeException
{
}
