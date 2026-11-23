<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Settings\SiteLogo;
use App\Storefront\Appearance\StorefrontAppearance;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * GET /favicon.ico (route `seo.favicon`, LOGO-1, site-logo.md): the site icon of the «Лого» tab as a real `.ico`. SESSIONLESS like /robots.txt (no session, no cookie, no `Set-Cookie`); with an icon:
 * `200 image/x-icon`, and StorefrontCacheHeaders gives it the contract's headers (`public, max-age=0, s-maxage=604800` + `xkey`), purged when the merchant saves. With NO icon (or a missing file, or
 * a damaged stored value) it answers a BARE 404, never cached: the head keeps the static SVG tag and no default ICO is shipped. (The empty `public/favicon.ico` this route replaces answered 200 with no body.)
 */
final class FaviconController extends Controller
{
    public function __invoke(StorefrontAppearance $appearance): Response
    {
        $url = null;

        try {
            $url = $appearance->siteLogo()['icon']['ico']['url'] ?? null;
        } catch (Throwable) {
            // a problem with the payload is "no icon"
        }

        if (is_string($url) && preg_match(SiteLogo::FILE, basename($url)) === 1) {
            $path = SiteLogo::DIRECTORY.'/'.basename($url);
            $disk = Storage::disk('public');

            if ($disk->exists($path)) {
                return response($disk->get($path), 200, ['Content-Type' => 'image/x-icon']);
            }
        }

        return response('', 404, ['Cache-Control' => 'private, no-store']);
    }
}
