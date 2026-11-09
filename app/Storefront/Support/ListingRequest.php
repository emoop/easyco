<?php

namespace App\Storefront\Support;

use App\Storefront\Exceptions\InvalidListingQuery;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\ListingScope;
use App\Storefront\ReadModels\ListingSort;
use Illuminate\Http\Request;

/**
 * The ONLY door from a request to a ListingQuery: the keys `page`, `per_page` and `sort` and nothing else (a price
 * filter or any other key in the URL is ignored, never passed on). A sort that the storefront does not OFFER (ListingSort::storefrontOptions(): the newest and the price in both directions; NOT the name) is refused
 * here like any unknown sort, so the reader's ListingOptionNotAvailable (a price FILTER) can never be reached from a request.
 */
final class ListingRequest
{
    public const KEYS = ['page', 'per_page', 'sort'];

    /** @throws InvalidListingQuery */
    public static function query(Request $request, ?string $categoryId = null, int $defaultPerPage = 24, ListingScope $scope = ListingScope::CATEGORY): ListingQuery
    {
        $input = $request->only(self::KEYS);

        // CD-1a: without a `per_page` in the URL the published «Каталог» size decides (24 for a shop that never set it); one in the URL is validated as ever
        array_key_exists('per_page', $input) || $input['per_page'] = $defaultPerPage;

        // P2: the id of the page's SUBJECT under the key of its scope (`category` by default; `brand`, `season`, `tag` for those pages): never read from the URL
        if ($categoryId !== null) {
            $input[$scope->value] = $categoryId;
        }

        $query = ListingQuery::fromRequestArray($input);

        if (! in_array($query->sort, ListingSort::storefrontOptions(), true)) {
            throw new InvalidListingQuery('sort', 'is not an offered sort');
        }

        return $query;
    }
}
