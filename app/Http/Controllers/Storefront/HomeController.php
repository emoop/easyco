<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Home\HomeView;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\PerPage;
use App\Storefront\Support\SeoMeta;
use Illuminate\Contracts\View\View;

/**
 * GET /  (route name `storefront.home`)
 *
 * The home page (S3; HP-1): the category tiles and the newest 12 products (no intro: its heading, text and button are gone), or the sections of the PUBLISHED home layout of «Дизайн» → «Начална».
 *
 * VIEW `storefront.home` receives EXACTLY:
 *   listing     App\Storefront\ReadModels\ListingPage  (newest 12, no pagination)
 *   categories  list<App\Storefront\ReadModels\CategoryNode>  the menu
 *   seo         array{title: string, description: string, canonical: string}
 */
final class HomeController extends Controller
{
    public function index(CatalogReader $reader, SeoMeta $seo, ?StorefrontAppearance $appearance = null): View
    {
        $appearance ??= app(StorefrontAppearance::class);
        // HP-1: a PUBLISHED home layout (from the cached appearance read: no query of its own) takes the page over: its sections (the page's <h1>, the shop's name, is kept for search engines and screen
        // readers). Nothing published (or a layout that does not validate): the category tiles and the newest products.
        $sections = $appearance->homeSections();

        if ($sections !== null) {
            // HP-4: the product grids are read NOW (prices, stock and visibility change): one card read each, no count
            $sections = HomeView::fill($sections, static fn (string $source, ?int $entity, string $sort, int $count, string $sizes): array => $reader->homeProducts($source, $entity, $sort, $count, $sizes));

            return view('storefront.home_sections', [
                'sections' => $sections,
                'seo' => $seo->forHome(route('storefront.home', [], false)),
            ]);
        }

        return view('storefront.home', [
            'listing' => $reader->listing(ListingQuery::create(perPage: PerPage::TWELVE)),
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forHome(route('storefront.home', [], false)),
        ]);
    }
}
