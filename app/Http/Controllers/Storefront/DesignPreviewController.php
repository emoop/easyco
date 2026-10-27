<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Settings\AppearanceSettings;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\BannerDrafts;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\CheckoutHeader\CheckoutHeaderCatalog;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\CheckoutHeader\CheckoutHeaderDrafts;
use App\Storefront\ProductSummary\ProductSummaryCatalog;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryDrafts;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\PriceFormatter;
use App\Storefront\Support\SeoMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * GET /design-preview?zone=checkout_header|topbar|product_summary (route `storefront.design.preview`): the preview of a zone of the «Дизайн» screen that has no preview route of its own (DS-2b; the top banner since DS-5; the product page's right column since SC-1). Reached only through a
 * temporary SIGNED url (30 minutes); a missing or bad signature, or a zone that is not known here, is the storefront's own 404. `noindex` and `Cache-Control: private, no-store`: a draft is
 * never public and never cached. The zone `checkout_header` shows the CART page with the admin's DRAFT simplified header (an empty cart is fine: the header is what matters); its colours and
 * logo heights go into a small preview stylesheet (CSS variables, like the published ones) linked after the token stylesheet, deleted an hour later. The header and footer previews keep their
 * own routes.
 */
final class DesignPreviewController extends Controller
{
    public const MINUTES = 30;

    public function show(Request $request, CatalogReader $reader, PriceFormatter $prices, StorefrontAppearance $appearance): Response
    {
        $zone = $request->query('zone');

        if (! URL::hasValidSignature($request) || ! in_array($zone, [CheckoutHeaderCatalog::ZONE, 'topbar', ProductSummaryCatalog::ZONE], true)) {
            return NotFoundResponse::make();
        }

        if ($zone === 'topbar') {
            return $this->topbar($reader, $appearance);
        }

        if ($zone === ProductSummaryCatalog::ZONE) {
            return $this->productSummary($reader, $appearance);
        }

        $draft = CheckoutHeaderDrafts::draft();
        // LG-1: the draft's logo picture is looked up for the preview (it may query; the public page never does)
        $show = ($draft !== null ? CheckoutHeaderCompiler::withLogo($draft) : null) ?? CheckoutHeaderCompiler::published(app(\App\Settings\Contracts\SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY));
        $show = $show !== null && $show['enabled'] === true ? $show : null;

        $appearance->previewCheckoutHeader($show, $show === null ? null : $this->stylesheet($show));

        return app(CartPageController::class)->show($reader, $prices)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * DS-5: the HOME page with the working copy of the top banner (its text, link, dates and colours), or the published banner when there is no draft. The draft is read raw and printed through the
     * published banner's own parser, its two colours go into a small preview stylesheet (CSS variables) linked after the token stylesheet. `noindex`, `no-store`.
     */
    private function topbar(CatalogReader $reader, StorefrontAppearance $appearance): Response
    {
        $draft = BannerDrafts::raw();

        if ($draft !== null) {
            $appearance->previewBanner(StorefrontAppearance::bannerFromValues($draft), $this->bannerStylesheet($draft));
        }

        $seo = app(SeoMeta::class);
        $view = app(HomeController::class)->index($reader, $seo, app(\App\Settings\StorefrontHomeSettings::class));
        $view->with('seo', ['title' => $seo->forHome(route('storefront.home', [], false))['title'], 'description' => '', 'canonical' => null, 'noindex' => true]);

        return response($view)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * SC-1: the product page of the FIRST visible product with the working copy of the right column (the draft, else the published layout, else the default one) injected for THIS request only
     * (StorefrontAppearance::previewProductSummary). The storefront itself reads the published layout only. An empty shop has no product to show: the storefront's own 404 (the panel says so under the
     * preview). `noindex`, `no-store`.
     */
    private function productSummary(CatalogReader $reader, StorefrontAppearance $appearance): Response
    {
        $slug = $reader->listing(ListingQuery::fromRequestArray([]))->items[0]->slug ?? null;

        if ($slug === null) {
            return NotFoundResponse::make();
        }

        $appearance->previewProductSummary(ProductSummaryCompiler::compiled(ProductSummaryDrafts::draft() ?? ProductSummaryDrafts::published()));

        $page = app(ProductController::class)->show($slug, $reader, app(SeoMeta::class), app(\App\Settings\Contracts\SiteSettingsRepository::class), $appearance);

        return ($page instanceof \Illuminate\Contracts\View\View ? response($page) : $page)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'private, no-store');
    }

    /** The draft banner's colours as a preview stylesheet, or null (no colour set, or one that is not `#rrggbb`: the shop's own colours then). @param array<string, mixed> $draft */
    private function bannerStylesheet(array $draft): ?string
    {
        $overrides = [];

        foreach ([AppearanceSettings::BANNER_COLOR_TEXT => 'text_color', AppearanceSettings::BANNER_COLOR_BG => 'bg_color'] as $key => $field) {
            $color = $draft[$field] ?? null;

            is_string($color) && AppearanceSettings::isHex($color) && $overrides[$key] = strtolower($color);
        }

        return $overrides === [] ? null : $this->write(app(AppearanceCompiler::class)->css($overrides));
    }

    /** A temporary signed url for the admin's preview frame. */
    public static function previewUrl(string $zone): string
    {
        return URL::temporarySignedRoute('storefront.design.preview', now()->addMinutes(self::MINUTES), ['zone' => $zone]);
    }

    /** The preview stylesheet (the draft's variables) or null when none is set. @param array<string, mixed> $values validated */
    private function stylesheet(array $values): ?string
    {
        return $this->write(app(AppearanceCompiler::class)->css([], [], [], [], [], CheckoutHeaderCompiler::vars($values)));
    }

    /** Stores a preview stylesheet (named by its content) and returns its url, or null for no css. */
    private function write(?string $css): ?string
    {
        $disk = Storage::disk('public');

        foreach ($disk->files(AppearanceCompiler::DIRECTORY) as $file) {
            if (str_contains($file, '/design-preview-') && $disk->lastModified($file) < time() - 3600) {
                $disk->delete($file);
            }
        }

        if ($css === null) {
            return null;
        }

        $path = AppearanceCompiler::DIRECTORY.'/design-preview-'.substr(sha1($css), 0, 12).'.css';
        Storage::disk('public')->put($path, $css);

        return '/storage/'.$path;
    }
}
