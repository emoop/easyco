<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Listing\ListingPageRenderer;
use App\Storefront\ReadModels\ListingScope;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\NotFoundResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * P2: GET /brand/{slug}, /season/{slug}, /product-tag/{slug}: the category page for another SUBJECT. The slug is looked up in the database (never trusted, never printed raw); an unknown one is the storefront's
 * one 404, a slug in another case is a 301 to the canonical one. Everything else is ListingPageRenderer's, the same as for a category.
 */
final class SubjectListingController extends Controller
{
    public function brand(Request $request, string $slug, CatalogReader $reader, ListingPageRenderer $renderer): View|RedirectResponse|Response
    {
        return $this->show($request, $slug, ListingScope::BRAND, $reader, $renderer);
    }

    public function season(Request $request, string $slug, CatalogReader $reader, ListingPageRenderer $renderer): View|RedirectResponse|Response
    {
        return $this->show($request, $slug, ListingScope::SEASON, $reader, $renderer);
    }

    public function tag(Request $request, string $slug, CatalogReader $reader, ListingPageRenderer $renderer): View|RedirectResponse|Response
    {
        return $this->show($request, $slug, ListingScope::TAG, $reader, $renderer);
    }

    private function show(Request $request, string $slug, ListingScope $scope, CatalogReader $reader, ListingPageRenderer $renderer): View|RedirectResponse|Response
    {
        $subject = $reader->subject($scope, $slug);

        if ($subject === null) {
            return NotFoundResponse::make();
        }

        return $renderer->render($request, $subject, $scope, $slug !== $subject->slug);
    }
}
