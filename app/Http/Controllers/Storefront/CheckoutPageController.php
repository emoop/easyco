<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\PaymentMethodAdapterResolver;
use App\Settings\CheckoutRegionPolicy;
use App\Settings\StoreCountry;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\PriceFormatter;
use EasyCo\Pricing\DefaultCurrency;
use Illuminate\Http\Response;

/**
 * GET /checkout  (route name `storefront.checkout`)
 *
 * A SERVER SHELL, driven by the browser (resources/js/storefront-checkout.js) on the EXISTING API: GET /api/cart, POST
 * /api/shipping/quote, POST /api/checkout. Like every storefront page it is cookie-free (no session, no cookie), answers
 * `Cache-Control: private, no-store` and is `noindex` (no canonical). The form markup, its labels and the payment methods are
 * rendered here; the script only fills in the delivery options, the summary and the totals, and submits. Without a cart
 * cookie (R1) or with an empty cart the script sends the visitor to /cart.
 *
 * VIEW `storefront.checkout` receives EXACTLY:
 *   categories      list<App\Storefront\ReadModels\CategoryNode>  the menu
 *   seo             array{title: string, description: string, canonical: null}
 *   priceTemplate   array{template: string, zero: string, currency: string}  as on the cart page
 *   paymentMethods  list<array{code: string, label: string}>  from PaymentMethodAdapterResolver::availableMethods(), the same
 *                   list the sandbox uses; the label is `storefront.payment_methods.{code}` (the code itself when unknown)
 *   defaultCountry  string  the store's own country code (BG when none is set)
 *   regionPolicy    array{default: string, countries: array<string, string>}  CheckoutRegionPolicy::all(), written into the page as a
 *                   JSON data attribute (an empty list of exceptions is `{}`); the script resolves the region mode of the current country
 */
final class CheckoutPageController extends Controller
{
    public function show(CatalogReader $reader, PriceFormatter $prices, PaymentMethodAdapterResolver $payments, StoreCountry $country, CheckoutRegionPolicy $regions): Response
    {
        $currency = DefaultCurrency::get();
        $zero = $currency->decimalPlaces() > 0 ? '0.'.str_repeat('0', $currency->decimalPlaces()) : '0';

        $methods = array_map(static function (string $code): array {
            $key = 'storefront.payment_methods.'.$code;
            $label = (string) __($key);

            return ['code' => $code, 'label' => $label === $key ? $code : $label];
        }, $payments->availableMethods());

        return response()
            ->view('storefront.checkout', [
                'categories' => $reader->categoryTree(),
                'seo' => [
                    'title' => __('storefront.checkout_title').' — '.config('app.name'),
                    'description' => '',
                    'canonical' => null,
                ],
                'priceTemplate' => ['template' => $prices->format(0, $currency->code()), 'zero' => $zero, 'currency' => $currency->code()],
                'paymentMethods' => $methods,
                'defaultCountry' => $country->currentOrNull() ?? 'BG',
                'regionPolicy' => $regions->all(),
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex');
    }
}
