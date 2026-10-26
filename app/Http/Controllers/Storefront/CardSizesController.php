<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Reader\CardSizeReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PC-2: GET /storefront/card-sizes?ids=12,15,18 — the sizes of the cards of one listing page, for the browser to fill in on hover (desktop) or when idle (touch).
 * Public, read-only, sessionless (the storefront group strips the session and cookies, so no Set-Cookie can leave), HTTP-cacheable for 60 seconds. A bad request
 * answers 422 with an EMPTY body: no detail is given to the caller.
 */
final class CardSizesController extends Controller
{
    /** Most product ids one request may ask for. */
    public const MAX_IDS = 48;

    public function show(Request $request, CardSizeReader $reader): JsonResponse
    {
        $ids = $this->ids($request->query('ids'));

        if ($ids === null) {
            return response()->json(new \stdClass, 422);
        }

        return response()
            ->json(['sizes' => (object) $reader->sizesFor($ids)])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=60');
    }

    /**
     * The ids as a list of distinct positive integers, or null: a comma-separated string of 1 to 48 ids, each a positive integer without a sign or leading zero.
     * An array (`ids[]=1`), a missing value and anything else is refused.
     *
     * @return list<int>|null
     */
    private function ids(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            if (preg_match('/^[1-9][0-9]{0,17}$/', $part) !== 1) {
                return null;
            }

            $ids[(int) $part] = true;
        }

        $ids = array_keys($ids);

        return count($ids) <= self::MAX_IDS ? $ids : null;
    }
}
