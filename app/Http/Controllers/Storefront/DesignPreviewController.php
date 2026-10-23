<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\CheckoutHeader\CheckoutHeaderCatalog;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\CheckoutHeader\CheckoutHeaderDrafts;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\PriceFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * GET /design-preview?zone=checkout_header (route `storefront.design.preview`): the preview of a zone of the «Дизайн» screen that has no preview route of its own (DS-2b). Reached only through a
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
        if (! URL::hasValidSignature($request) || $request->query('zone') !== CheckoutHeaderCatalog::ZONE) {
            return NotFoundResponse::make();
        }

        $draft = CheckoutHeaderDrafts::draft();
        $show = $draft ?? CheckoutHeaderCompiler::published(app(\App\Settings\Contracts\SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY));
        $show = $show !== null && $show['enabled'] === true ? $show : null;

        $appearance->previewCheckoutHeader($show, $show === null ? null : $this->stylesheet($show));

        return app(CartPageController::class)->show($reader, $prices)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'private, no-store');
    }

    /** A temporary signed url for the admin's preview frame. */
    public static function previewUrl(string $zone): string
    {
        return URL::temporarySignedRoute('storefront.design.preview', now()->addMinutes(self::MINUTES), ['zone' => $zone]);
    }

    /** The preview stylesheet (the draft's variables) or null when none is set. @param array<string, mixed> $values validated */
    private function stylesheet(array $values): ?string
    {
        $disk = Storage::disk('public');

        foreach ($disk->files(AppearanceCompiler::DIRECTORY) as $file) {
            if (str_contains($file, '/design-preview-') && $disk->lastModified($file) < time() - 3600) {
                $disk->delete($file);
            }
        }

        $css = app(AppearanceCompiler::class)->css([], [], [], [], [], CheckoutHeaderCompiler::vars($values));

        if ($css === null) {
            return null;
        }

        $path = AppearanceCompiler::DIRECTORY.'/design-preview-'.substr(sha1($css), 0, 12).'.css';
        $disk->put($path, $css);

        return '/storage/'.$path;
    }
}
