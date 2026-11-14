<?php

namespace App\Storefront\Search;

use Illuminate\Support\Facades\Route;

/**
 * Is storefront search switched on? The header builder prints a search element only when its feature exists (HeaderCatalog::FEATURE_ROUTES); here that is
 * a `storefront.search` route (what the older header tests register) OR the operator switch `storefront.search.enabled` (env STOREFRONT_SEARCH, OFF by default:
 * a shop that has not switched it on renders exactly the HTML it rendered before, and /search is the storefront's 404).
 */
final class SearchFeature
{
    public const PATH = '/search';

    public static function enabled(): bool
    {
        return (bool) config('storefront.search.enabled', false) || Route::has('storefront.search');
    }

    /**
     * The phone drawer's search row. With the operator switch it needs the row's own switch in the builder (`mobile.drawer_search`, default OFF: a shop that never turned it on prints the HTML it printed before).
     * Where a `storefront.search` route was registered by other code (the header tests and their golden fixtures do) the row keeps the behaviour it had before SRCH-1: it is printed.
     */
    public static function drawerRow(mixed $layoutSwitch): bool
    {
        return Route::has('storefront.search') || ((bool) config('storefront.search.enabled', false) && $layoutSwitch === true);
    }

    /** The form action: the same bytes `route('storefront.search', [], false)` printed. */
    public static function path(): string
    {
        return self::PATH;
    }
}
