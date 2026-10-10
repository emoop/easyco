<?php

namespace App\Storefront\Support;

use App\Storefront\Exceptions\InvalidListingQuery;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\ListingSort;
use Illuminate\Http\Request;

/**
 * The ONLY door from a request to a ListingQuery: the keys `page`, `per_page` and `sort` and nothing else (a price
 * filter or any other key in the URL is ignored, never passed on). A price sort is valid for ListingQuery but is not
 * offered by the storefront until S7, so it is refused here like any unknown sort: the reader's
 * ListingOptionNotAvailable can therefore never be reached from a request.
 */
final class ListingRequest
{
    public const KEYS = ['page', 'per_page', 'sort'];

    /** @throws InvalidListingQuery */
    public static function query(Request $request, ?string $categoryId = null): ListingQuery
    {
        $input = $request->only(self::KEYS);

        if ($categoryId !== null) {
            $input['category'] = $categoryId;
        }

        $query = ListingQuery::fromRequestArray($input);

        if ($query->sort === ListingSort::PRICE_ASC || $query->sort === ListingSort::PRICE_DESC) {
            throw new InvalidListingQuery('sort', 'is not available yet');
        }

        return $query;
    }
}
