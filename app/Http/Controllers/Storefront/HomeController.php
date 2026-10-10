<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\PerPage;
use App\Storefront\Support\SeoMeta;
use Illuminate\Contracts\View\View;

/**
 * GET /  (route name `storefront.home`)
 *
 * A PLACEHOLDER home (the real one is S3): the category menu and the newest 12 products.
 *
 * VIEW `storefront.home` receives EXACTLY:
 *   listing     App\Storefront\ReadModels\ListingPage  (newest 12, no pagination)
 *   categories  list<App\Storefront\ReadModels\CategoryNode>  the menu
 *   seo         array{title: string, description: string, canonical: string}
 */
final class HomeController extends Controller
{
    public function index(CatalogReader $reader, SeoMeta $seo): View
    {
        return view('storefront.home', [
            'listing' => $reader->listing(ListingQuery::create(perPage: PerPage::TWELVE)),
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forHome(route('storefront.home', [], false)),
        ]);
    }
}
