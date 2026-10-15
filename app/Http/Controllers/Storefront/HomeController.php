<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Settings\StorefrontHomeSettings;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\PerPage;
use App\Storefront\Support\SeoMeta;
use Illuminate\Contracts\View\View;

/**
 * GET /  (route name `storefront.home`)
 *
 * The home page (S3, S3-V): the editable intro, the category tiles and the newest 12 products.
 *
 * VIEW `storefront.home` receives EXACTLY:
 *   listing     App\Storefront\ReadModels\ListingPage  (newest 12, no pagination)
 *   categories  list<App\Storefront\ReadModels\CategoryNode>  the menu
 *   seo         array{title: string, description: string, canonical: string}
 *   introTitle  ?string  the merchant's heading (StorefrontHomeSettings), null = the language default
 *   introText   ?string  the merchant's text, null = the language default
 */
final class HomeController extends Controller
{
    public function index(CatalogReader $reader, SeoMeta $seo, StorefrontHomeSettings $intro): View
    {
        // The merchant's heading and text (null = the language default): read BEFORE the listing is formatted, so the settings read is shared (no new query).
        $title = $intro->title();
        $text = $intro->text();

        return view('storefront.home', [
            'introTitle' => $title,
            'introText' => $text,
            'listing' => $reader->listing(ListingQuery::create(perPage: PerPage::TWELVE)),
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forHome(route('storefront.home', [], false)),
        ]);
    }
}
