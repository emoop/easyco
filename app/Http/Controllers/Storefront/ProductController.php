<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
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
 *
 * A missing, hidden, draft, archived or soft-deleted product, or an invalid slug: the storefront's one 404
 * (NotFoundResponse). A slug that matches only by case (the database collation) is a 301 to the canonical url that the
 * read model carries; the controller builds no url itself.
 */
final class ProductController extends Controller
{
    public function show(string $slug, CatalogReader $reader, SeoMeta $seo): View|RedirectResponse|Response
    {
        $product = $reader->product($slug);

        if ($product === null) {
            return NotFoundResponse::make();
        }

        if ($product->slug !== $slug) {
            return redirect()->to($product->url, 301);
        }

        return view('storefront.product', [
            'product' => $product,
            'descriptionHtml' => DescriptionSanitizer::clean($product->description),
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forProduct($product),
        ]);
    }
}
