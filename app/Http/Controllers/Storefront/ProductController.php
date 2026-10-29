<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\GallerySettings;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Conditions\CurrentProduct;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\ProductSummary\ProductSummaryView;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\DescriptionSanitizer;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\SeoMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * GET /product/{slug}
 *
 * VIEW `storefront.product` receives EXACTLY:
 *   product          App\Storefront\ReadModels\ProductPage (raw text: the view escapes with {{ }})
 *   descriptionHtml  string  the product description, already passed through DescriptionSanitizer: the ONLY value the
 *                    view may print with {!! !!}
 *   categories       list<App\Storefront\ReadModels\CategoryNode>  the menu (free once the reader's CategoryIndex is loaded)
 *   seo              array{title: string, description: string, canonical: string}
 *   gallerySettings  App\Settings\GallerySettings  the gallery's modes, width, odd-last rule and hover zoom (closed sets, with defaults)
 *   summary          list<array{type: string, html: string}>  SC-1: the components of the right column in the order of the PUBLISHED layout (the default layout when none is published);
 *                    HB-2: an HTML block whose «Показвай, когато…» conditions do not hold for THIS product is not in the list
 *
 * A missing, hidden, draft, archived or soft-deleted product, or an invalid slug: the storefront's one 404
 * (NotFoundResponse). A slug that matches only by case (the database collation) is a 301 to the canonical url that the
 * read model carries; the controller builds no url itself.
 */
final class ProductController extends Controller
{
    public function show(string $slug, CatalogReader $reader, SeoMeta $seo, SiteSettingsRepository $settings, StorefrontAppearance $appearance): View|RedirectResponse|Response
    {
        $product = $reader->product($slug);

        if ($product === null) {
            return NotFoundResponse::make();
        }

        if ($product->slug !== $slug) {
            return redirect()->to($product->url, 301);
        }

        // HB-2: THE hook: this product is the one `@productIs` and the conditions of an HTML block look at, for this request only
        $context = ProductContext::fromPage($product);
        app(CurrentProduct::class)->set($context);

        return view('storefront.product', [
            'product' => $product,
            'descriptionHtml' => DescriptionSanitizer::clean($product->description),
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forProduct($product),
            'gallerySettings' => GallerySettings::fromRepository($settings),
            'summary' => ProductSummaryView::forProduct($appearance->productSummary(), $context),
        ]);
    }
}
