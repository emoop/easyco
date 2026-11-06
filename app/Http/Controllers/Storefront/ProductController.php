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
 *   (P4b.1: the description is no block of its own any more: it is the text of a `description` section, see SectionResolver)
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

        // P4b: the sections (the «Акордеон» of the right column, the «Табове» slot below): the definitions come with the compiled document (0 queries); the product's own texts cost ONE query, and only when the page
        // uses sections AND some section takes a product text
        $description = DescriptionSanitizer::clean($product->description);
        $summary = ProductSummaryView::forProduct($appearance->productSummary(), $context);
        $belowCompiled = $appearance->productBelow();
        $definitions = $appearance->productSections();
        $usesSections = $definitions !== null && (in_array('accordion', array_column($summary, 'type'), true) || in_array('tabs', array_column($belowCompiled['slots'] ?? [], 'type'), true));
        // P4c: ONE read of the product's texts AND its «Показвай на този продукт» switches whenever the page prints sections; one read of its season and tags only when a `meta` section is defined
        $rows = $usesSections ? \App\Storefront\Sections\SectionTexts::rowsForProduct((int) $product->id) : ['texts' => [], 'hidden' => []];
        $meta = $usesSections && \App\Storefront\Sections\SectionMeta::isUsed($definitions) ? \App\Storefront\Sections\SectionMeta::forProduct((int) $product->id, app(\App\Storefront\Url\StorefrontUrls::class)) : null;
        $sections = $usesSections ? \App\Storefront\Sections\SectionResolver::resolve($definitions, $rows['texts'], $description, $rows['hidden'], $meta, $context) : [];

        return view('storefront.product', [
            'product' => $product,
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forProduct($product),
            'gallerySettings' => GallerySettings::fromRepository($settings),
            'summary' => $summary,
            'sections' => $sections,
            // P4: the slots below the main area («Под галерията»): null = nothing (no query, no markup)
            'below' => \App\Storefront\Below\BelowView::forProduct(
                $belowCompiled,
                $context,
                static fn (string $source, ?int $entity, string $sort, int $count, string $sizes): array => $reader->homeProducts($source, $entity, $sort, $count, $sizes, null, (int) $product->id),
                $appearance->catalogGapX(),
                $appearance->catalogColumns()['phone'],
                $sections,
            ),
        ]);
    }
}
