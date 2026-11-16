<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Index\SubjectIndex;
use App\Storefront\ReadModels\Breadcrumb;
use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\Support\NotFoundResponse;
use App\Storefront\Support\SeoMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * SEO-1.1: GET /brand and GET /collection, the index pages of the two kinds whose own pages are `/brand/{slug}` and `/collection/{slug}`.
 * A kind with no listed row (no brand with a product; the collections switch off; no collection with a product) is the storefront's 404, so the page,
 * the sitemap and the menu stay consistent. The rows and their pictures are `SubjectIndex`; the page is `storefront.subject-index`.
 */
final class SubjectIndexController extends Controller
{
    public function brands(SubjectIndex $index, SeoMeta $seo): View|Response
    {
        return $this->page(CatalogSubjectKind::BRAND, $index, $seo);
    }

    public function collections(SubjectIndex $index, SeoMeta $seo): View|Response
    {
        return $this->page(CatalogSubjectKind::COLLECTION, $index, $seo);
    }

    private function page(CatalogSubjectKind $kind, SubjectIndex $index, SeoMeta $seo): View|Response
    {
        $tiles = $index->tiles($kind);

        if ($tiles === []) {
            return NotFoundResponse::make();
        }

        // P8b: the index page's key
        app(\App\Storefront\Cache\SurrogateKeys::class)->add($kind === CatalogSubjectKind::BRAND ? 'idx-brand' : 'idx-collection');

        $title = (string) __('storefront.index_'.$kind->value.'_title');
        $lead = (string) __('storefront.index_'.$kind->value.'_lead');
        // the trail's last step is the page itself: printed without a link, and without an `item` url in the JSON-LD
        $crumbs = [new Breadcrumb((string) __($kind->crumbKey()), '')];

        return view('storefront.subject-index', [
            'kind' => $kind->value,
            'title' => $title,
            'lead' => $lead,
            'tiles' => $tiles,
            'groups' => $kind === CatalogSubjectKind::BRAND ? SubjectIndex::groups($tiles) : [],
            'crumbs' => $crumbs,
            'seo' => $seo->forSubjectIndex($title, $lead, '/'.trim($kind->urlPrefix(), '/'), $crumbs),
        ]);
    }
}
