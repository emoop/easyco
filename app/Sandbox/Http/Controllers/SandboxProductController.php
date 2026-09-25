<?php

namespace App\Sandbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Sandbox\SandboxProductListPage;
use App\Sandbox\SandboxProductPage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * The sandbox storefront's two pages — prompt D, D2/D3/D7/D8.
 *
 * READ-ONLY, AND DELIBERATELY THIN: this controller reads (through the
 * sandbox's own read classes, never by touching a domain repository
 * itself), renders, and returns. There is no write path, no form
 * handling, no validation, no session write, and no second action that
 * could accept a POST — D8's "no writes of any kind" is a property of the
 * whole feature, and a controller with no write method is the cheapest
 * way to keep it true.
 *
 * NO AUTHORIZATION MIDDLEWARE, ON PURPOSE: this is a preview surface
 * behind a flag only a developer/merchant turns on (config/sandbox.php),
 * showing exactly what D3 says a customer may see. It is not a merchant
 * endpoint, so `staff.can:*` does not apply — and it must NOT become one:
 * a future stage that adds a cart would go through the real Cart/Checkout
 * API endpoints, which already carry `auth`/permissions, rather than
 * inventing a sandbox-local write path.
 *
 * THE 404 IS A RENDERED RESPONSE, NOT an abort(404) — see
 * NoIndexHeaders' own docblock: an HttpException thrown from inside a
 * route is rendered by Laravel's exception handler AFTER the middleware
 * pipeline has already unwound, so the X-Robots-Tag header this group's
 * middleware guarantees would be missing from it. Returning the view with
 * status 404 keeps the status semantics AND the noindex contract.
 */
final class SandboxProductController extends Controller
{
    public function __construct(
        private readonly SandboxProductListPage $listPage,
        private readonly SandboxProductPage $productPage,
    ) {
    }

    public function index(): View
    {
        return view('sandbox.products', [
            'products' => $this->listPage->paginate(),
        ]);
    }

    /**
     * D3: "a non-listed product's page returns 404" — the reader returns
     * null for a DRAFT/ARCHIVED/HIDDEN product, and a nonexistent id
     * behaves identically (there is no separate "not found" path, so the
     * page cannot disclose which of the two happened).
     */
    public function show(string $productId): View|Response
    {
        $product = $this->productPage->forProduct($productId);

        if ($product === null) {
            return response()->view('sandbox.product-not-found', ['productId' => $productId], 404);
        }

        return view('sandbox.product', ['product' => $product]);
    }
}
