<?php

namespace App\Sandbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Sandbox\SandboxProductListPage;
use App\Sandbox\SandboxProductPage;
use App\Services\PaymentMethodAdapterResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * The sandbox storefront's pages — prompt D, D2/D3/D7/D8 and the D-stage
 * cart/checkout/confirmation pages.
 *
 * EVERY METHOD HERE IS A GET THAT RENDERS. There is still no form handling, no
 * validation, no session write and no POST action of any kind in this class: the
 * cart and checkout pages render shells whose JavaScript calls the REAL API
 * (routes/api.php), which owns CSRF, identification and every write. D8's "no
 * writes" is satisfied by the sandbox having no write PATH — the write happens in
 * the real storefront API, reached from the browser exactly as a real storefront
 * would reach it, which is the point of staging this surface at all.
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
        private readonly PaymentMethodAdapterResolver $adapterResolver,
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

    /**
     * D4's page. Renders a SHELL and nothing else: the cart belongs to whoever is
     * browsing (a guest's session token, or a logged-in customer's account), so
     * rendering lines server-side would mean this page deciding who "the customer"
     * is — the exact job cart-domain-design.md §10 gives to the Cart API, using a
     * session cookie this page would then have to write. Instead the page asks
     * GET /api/cart from the browser with the credentials it already has, and
     * renders what that API returns. One identification rule, in one place.
     */
    public function cart(): View
    {
        return view('sandbox.cart');
    }

    /**
     * D5's page. The payment methods are the ONE thing here a shell cannot get from
     * the API (there is no "list methods" endpoint, and inventing one would put
     * storefront UI concerns inside the checkout domain), so they come from
     * PaymentMethodAdapterResolver::availableMethods() — the one place the known
     * codes live — and are printed as the real strings POST /api/checkout accepts.
     * Nothing is hardcoded in the view.
     */
    public function checkout(): View
    {
        return view('sandbox.checkout', [
            'paymentMethods' => $this->adapterResolver->availableMethods(),
        ]);
    }

    /**
     * D6's page. Rendered from NOTHING on the server: the order the customer just
     * placed is in sessionStorage (written by the checkout page from the 201
     * response), so a reload shows what the customer saw, and there is deliberately
     * NO /_sandbox/order-placed/{id} route at all — no id is ever looked up, so no
     * order can be read by anyone who has not just placed it. SandboxRoutesDisabledTest
     * asserts that id route 404s.
     */
    public function orderPlaced(): View
    {
        return view('sandbox.order-placed');
    }
}
