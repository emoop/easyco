<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Settings\CookieSettings;
use App\Settings\StoreLocale;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cookies\Contrast;
use App\Storefront\Support\NotFoundResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * GET /cookie-preview (route `storefront.cookies.preview`): the admin's STATIC, inert preview of the cookie banner (cookie-consent.md). The REAL banner partial, the draft values the admin page
 * cached under a token, shown visible on an empty page: NO script, NO cookie (read or written), no footer link. Reached only through a temporary SIGNED url (30 minutes); a missing or bad
 * signature is the storefront's own 404. `noindex`, `Cache-Control: private, no-store`. The draft colour is a small stylesheet (`cookie-preview-{hash}.css`, deleted an hour later).
 */
final class CookiePreviewController extends Controller
{
    public const MINUTES = 30;

    public const CACHE_PREFIX = 'cookies.preview.';

    public function show(Request $request, StorefrontAppearance $appearance): Response
    {
        if (! URL::hasValidSignature($request)) {
            return NotFoundResponse::make();
        }

        $token = (string) $request->query('t', '');
        $draft = preg_match('/^[0-9a-f]{40}$/', $token) === 1 ? Cache::get(self::CACHE_PREFIX.$token) : null;

        if (! is_array($draft)) {
            return NotFoundResponse::make();
        }

        $locale = (new StoreLocale(app(\App\Settings\Contracts\SiteSettingsRepository::class)))->current();
        $locale = in_array($locale, CookieSettings::LOCALES, true) ? $locale : 'bg';

        $cc = [
            'position' => in_array($draft['position'] ?? null, CookieSettings::POSITIONS, true) ? $draft['position'] : 'center',
            'view' => in_array($draft['view'] ?? null, CookieSettings::VIEWS, true) ? $draft['view'] : 'summary',
            'months' => 12,
            'version' => 1,
            'texts' => CookieSettings::resolveTexts(is_array($draft['texts'][$locale] ?? null) ? $draft['texts'][$locale] : [], $locale),
            'policy' => $this->policy($draft['page'] ?? null),
        ];

        $layer = ($draft['layer'] ?? 'summary') === 'details' ? 'details' : 'summary';

        return response()->view('storefront.cookie-preview', [
            'cc' => $cc,
            'layer' => $layer,
            'tokensUrl' => $appearance->forLayout()['tokensUrl'],
            'mode' => $appearance->mode(),
            'previewCss' => $this->stylesheet($draft['color'] ?? null),
            'locale' => $locale,
        ])->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'private, no-store');
    }

    /** A temporary signed url for the admin page's preview frame; the draft is cached under a token made of its own content. @param array<string, mixed> $draft */
    public static function previewUrl(array $draft): string
    {
        $token = sha1((string) json_encode($draft));
        Cache::put(self::CACHE_PREFIX.$token, $draft, now()->addMinutes(self::MINUTES));

        return URL::temporarySignedRoute('storefront.cookies.preview', now()->addMinutes(self::MINUTES), ['t' => $token]);
    }

    /** @return array{slug: string, title: string}|null a PUBLISHED page only */
    private function policy(mixed $id): ?array
    {
        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        $page = DB::table('pages')->where('id', (int) $id)->where('status', 'published')->first(['slug', 'title']);

        return $page === null ? null : ['slug' => (string) $page->slug, 'title' => (string) $page->title];
    }

    private function stylesheet(mixed $color): ?string
    {
        $disk = Storage::disk('public');

        foreach ($disk->files(AppearanceCompiler::DIRECTORY) as $file) {
            if (str_contains($file, '/cookie-preview-') && $disk->lastModified($file) < time() - 3600) {
                $disk->delete($file);
            }
        }

        $color = strtolower((string) $color);

        if (! Contrast::isHex($color) || $color === CookieSettings::DEFAULT_COLOR) {
            return null;
        }

        $css = ":root {\n    --sf-cc-btn: {$color};\n    --sf-cc-btn-text: ".Contrast::bestText($color)['color'].";\n}\n";
        $path = AppearanceCompiler::DIRECTORY.'/cookie-preview-'.substr(sha1($css), 0, 12).'.css';
        $disk->put($path, $css);

        return '/storage/'.$path;
    }
}
