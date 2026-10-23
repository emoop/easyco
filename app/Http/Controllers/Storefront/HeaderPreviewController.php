<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StorefrontHomeSettings;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\SeoMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * GET /header-preview (route `storefront.header.preview`): the HOME page with the admin's DRAFT header (storefront-customization-design.md 4.3, D2). Reached
 * only through a temporary SIGNED url (30 minutes) made by the header page; a missing or bad signature is the storefront's own 404. `noindex` and
 * `Cache-Control: private, no-store`: a draft is never public and never cached. The draft's row and logo heights are written to a small preview stylesheet
 * (CSS variables, like the published ones) linked after the token stylesheet; it is deleted an hour later.
 */
final class HeaderPreviewController extends Controller
{
    public const MINUTES = 30;

    public function show(Request $request, CatalogReader $reader, SeoMeta $seo, StorefrontHomeSettings $intro, StorefrontAppearance $appearance, SiteSettingsRepository $settings): Response
    {
        if (! URL::hasValidSignature($request)) {
            return NotFoundResponse::make();
        }

        $draft = $settings->get(AppearanceSettings::HEADER_DRAFT_KEY);
        $layout = is_string($draft) ? json_decode($draft, true) : null;
        $validation = is_array($layout) ? HeaderValidator::validate($layout) : null;
        $show = $validation !== null && $validation->passes() ? $validation->layout : $appearance->header();

        $appearance->previewHeader($show, $this->stylesheet($show));

        $view = app(HomeController::class)->index($reader, $seo, $intro);
        $view->with('seo', ['title' => $seo->forHome(route('storefront.home', [], false))['title'], 'description' => '', 'canonical' => null, 'noindex' => true]);

        return response($view)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'private, no-store');
    }

    /** A temporary signed url for the admin's Preview action. */
    public static function previewUrl(): string
    {
        return URL::temporarySignedRoute('storefront.header.preview', now()->addMinutes(self::MINUTES));
    }

    /** The preview stylesheet (the draft's sizes as CSS variables) or null when every size is a default. @param array<string, mixed> $layout */
    private function stylesheet(array $layout): ?string
    {
        $disk = Storage::disk('public');

        foreach ($disk->files(AppearanceCompiler::DIRECTORY) as $file) {
            if (str_contains($file, '/header-preview-') && $disk->lastModified($file) < time() - 3600) {
                $disk->delete($file);
            }
        }

        $css = app(AppearanceCompiler::class)->css([], HeaderLayout::metrics($layout), [], [], HeaderLayout::colorVars($layout));

        if ($css === null) {
            return null;
        }

        $path = AppearanceCompiler::DIRECTORY.'/header-preview-'.substr(sha1($css), 0, 12).'.css';
        $disk->put($path, $css);

        return '/storage/'.$path;
    }
}
