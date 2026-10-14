<?php

namespace App\Http\Controllers\Storefront;

use App\Content\Models\PageModel;
use App\Http\Controllers\Controller;
use App\Storefront\Pages\PageReader;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\DescriptionSanitizer;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\SeoMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * GET /info/{slug} and GET /info-preview/{id}  (pages-design.md section 4)
 *
 * VIEW `storefront.page` receives EXACTLY:
 *   page       App\Content\Models\PageModel (title and the SEO fields are plain text: the view escapes them with {{ }})
 *   bodyHtml   string  the page body passed through DescriptionSanitizer AT RENDER (a row edited by hand in the database still cannot
 *              carry a script): the ONLY value the view may print with {!! !!}
 *   categories list<CategoryNode>  the menu
 *   seo        array{title: string, description: string, canonical: ?string, noindex?: bool}
 *
 * A draft, an archived or an unknown page, or an invalid slug: the storefront's one 404 (NotFoundResponse), the same body for all of
 * them. A slug that matches only by case (the column collation) is a 301 to the canonical url. The PREVIEW shows a page whatever its
 * status, but only through a temporary signed url (a bad or expired signature is the same 404), never indexed.
 */
final class PageController extends Controller
{
    /** How long a preview link lives. */
    public const PREVIEW_MINUTES = 30;

    public function show(string $slug, PageReader $pages, CatalogReader $reader, SeoMeta $seo): View|RedirectResponse|Response
    {
        $page = $pages->findPublishedBySlug($slug);

        if ($page === null) {
            return NotFoundResponse::make();
        }

        if ($page->slug !== $slug) {
            return redirect()->to(PageReader::urlFor($page->slug), 301);
        }

        return $this->render($page, $reader, $seo, preview: false);
    }

    public function preview(string $id, Request $request, CatalogReader $reader, SeoMeta $seo): View|Response
    {
        $page = ctype_digit($id) && URL::hasValidSignature($request) ? PageModel::query()->find((int) $id) : null;

        if ($page === null) {
            return NotFoundResponse::make();
        }

        return response($this->render($page, $reader, $seo, preview: true))
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'private, no-store');
    }

    private function render(PageModel $page, CatalogReader $reader, SeoMeta $seo, bool $preview): View
    {
        return view('storefront.page', [
            'page' => $page,
            'bodyHtml' => DescriptionSanitizer::clean($page->body),
            'categories' => $reader->categoryTree(),
            'seo' => $seo->forPage($page, $preview),
        ]);
    }

    /** A temporary signed url for the admin's Preview action. */
    public static function previewUrl(PageModel $page): string
    {
        return URL::temporarySignedRoute('storefront.page.preview', now()->addMinutes(self::PREVIEW_MINUTES), ['id' => $page->id]);
    }
}
