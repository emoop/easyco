<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StorefrontHomeSettings;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Footer\FooterCompiler;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\SeoMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * GET /footer-preview (route `storefront.footer.preview`): the HOME page with the admin's DRAFT footer (FT-1, like the header's preview). Reached only through a temporary SIGNED
 * url (30 minutes) made by the footer page; a missing or bad signature is the storefront's own 404. `noindex` and `Cache-Control: private, no-store`: a draft is never public and
 * never cached. The draft's colours and border go into a small preview stylesheet (CSS variables, like the published ones; a colour the draft leaves empty is reset so the
 * published footer's colour cannot show through) linked after the token stylesheet; it is deleted an hour later.
 */
final class FooterPreviewController extends Controller
{
    public const MINUTES = 30;

    private const VARIABLES = ['--sf-ftu-bg', '--sf-ftu-text', '--sf-ftu-bc', '--sf-ftu-bw', '--sf-ftl-bg', '--sf-ftl-text'];

    public function show(Request $request, CatalogReader $reader, SeoMeta $seo, StorefrontHomeSettings $intro, StorefrontAppearance $appearance, SiteSettingsRepository $settings): Response
    {
        if (! URL::hasValidSignature($request)) {
            return NotFoundResponse::make();
        }

        $draft = $settings->get(AppearanceSettings::FOOTER_DRAFT_KEY);
        $layout = is_string($draft) ? json_decode($draft, true) : null;
        $validation = is_array($layout) ? FooterValidator::validate($layout) : null;

        if ($validation !== null && $validation->passes()) {
            $appearance->previewFooter(FooterCompiler::compiled($validation->layout), $this->stylesheet($validation->layout));
        }

        $view = app(HomeController::class)->index($reader, $seo, $intro);
        $view->with('seo', ['title' => $seo->forHome(route('storefront.home', [], false))['title'], 'description' => '', 'canonical' => null, 'noindex' => true]);

        return response($view)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'private, no-store');
    }

    /** A temporary signed url for the admin's Preview action. */
    public static function previewUrl(): string
    {
        return URL::temporarySignedRoute('storefront.footer.preview', now()->addMinutes(self::MINUTES));
    }

    /** The preview stylesheet: the draft's variables, every other one reset. @param array<string, mixed> $layout validated */
    private function stylesheet(array $layout): string
    {
        $disk = Storage::disk('public');

        foreach ($disk->files(AppearanceCompiler::DIRECTORY) as $file) {
            if (str_contains($file, '/footer-preview-') && $disk->lastModified($file) < time() - 3600) {
                $disk->delete($file);
            }
        }

        $vars = FooterLayout::vars($layout);
        $lines = [];

        foreach (self::VARIABLES as $variable) {
            $lines[] = $variable.': '.($vars[$variable] ?? 'initial').';';
        }

        $css = ":root {\n    ".implode("\n    ", $lines)."\n}\n";
        $path = AppearanceCompiler::DIRECTORY.'/footer-preview-'.substr(sha1($css), 0, 12).'.css';
        $disk->put($path, $css);

        return '/storage/'.$path;
    }
}
