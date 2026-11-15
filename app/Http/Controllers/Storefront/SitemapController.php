<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\Seo\Sitemap;
use App\Storefront\Support\NotFoundResponse;
use Illuminate\Http\Response;

/**
 * GET /sitemap.xml and /sitemap/{pages|categories|products-N}.xml (SEO-1, seo.md). The files are built and cached by `Sitemap`; with `easyco.noindex` (a test server) every one of them is the storefront's 404,
 * as is a chunk past the last one.
 */
final class SitemapController extends Controller
{
    public function index(Sitemap $sitemap): Response
    {
        return $this->answer(config('easyco.noindex') ? null : $sitemap->index());
    }

    public function pages(Sitemap $sitemap): Response
    {
        return $this->answer(config('easyco.noindex') ? null : $sitemap->pages());
    }

    public function categories(Sitemap $sitemap): Response
    {
        return $this->answer(config('easyco.noindex') ? null : $sitemap->categories());
    }

    public function products(string $n, Sitemap $sitemap): Response
    {
        return $this->answer(config('easyco.noindex') ? null : $sitemap->products((int) $n));
    }

    /** SEO-1.1: the brands, seasons, collections and tags that have a row of their index (the file is 404 when there is none, and always 404 under noindex). */
    public function brands(Sitemap $sitemap): Response
    {
        return $this->answer(config('easyco.noindex') ? null : $sitemap->subject(CatalogSubjectKind::BRAND));
    }

    public function seasons(Sitemap $sitemap): Response
    {
        return $this->answer(config('easyco.noindex') ? null : $sitemap->subject(CatalogSubjectKind::SEASON));
    }

    public function collections(Sitemap $sitemap): Response
    {
        return $this->answer(config('easyco.noindex') ? null : $sitemap->subject(CatalogSubjectKind::COLLECTION));
    }

    public function tags(Sitemap $sitemap): Response
    {
        return $this->answer(config('easyco.noindex') ? null : $sitemap->subject(CatalogSubjectKind::TAG));
    }

    private function answer(?string $xml): Response
    {
        if ($xml === null) {
            return NotFoundResponse::make();
        }

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
