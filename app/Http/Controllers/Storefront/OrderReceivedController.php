<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Orders\OrderReceivedReader;
use App\Storefront\Support\NotFoundResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * GET /order/received/{orderNumber}?expires=...&signature=...  (route name `storefront.order.received`)
 *
 * The page a guest lands on after checkout, reached ONLY through the signed, temporary url (30 days) that
 * CheckoutController returns as `confirmation_url` (App\Storefront\Orders\OrderConfirmationUrl). The signature is checked
 * here, not by the framework's `signed` middleware, so that a bad or expired signature, a tampered number and an unknown
 * order all end in the storefront's own 404 (the same body, nothing says which): the framework would answer 403.
 * No session, no cookie. `Cache-Control: private, no-store`, noindex and `Referrer-Policy: no-referrer` (the url is the
 * key to the order and must never leak in a Referer): set by OrderReceivedHeaders on the route group.
 *
 * VIEW `storefront.order-received` receives EXACTLY:
 *   order       array  OrderReceivedReader::find() (raw plain text, amounts already formatted)
 *   categories  []     (no menu: the page is a receipt)
 *   seo         array{title: string, description: string, canonical: null}
 */
final class OrderReceivedController extends Controller
{
    public function show(Request $request, string $orderNumber, OrderReceivedReader $reader): Response
    {
        if (! URL::hasValidSignature($request)) {
            return NotFoundResponse::make();
        }

        $order = $reader->find($orderNumber);

        if ($order === null) {
            return NotFoundResponse::make();
        }

        return response()->view('storefront.order-received', [
            'order' => $order,
            'categories' => [],
            'seo' => [
                'title' => __('storefront.received_title').' — '.config('app.name'),
                'description' => '',
                'canonical' => null,
            ],
        ]);
    }
}
