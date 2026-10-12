<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\PriceFormatter;
use EasyCo\Pricing\DefaultCurrency;
use Illuminate\Http\Response;

/**
 * GET /cart  (route name `storefront.cart`)
 *
 * A SERVER SHELL, filled by the browser (storefront.js) from GET /api/cart. The shell itself belongs to the cookie-free
 * storefront group like every other page: it starts no session, sets no cookie and reads no cart. It answers
 * `Cache-Control: private, no-store` and is `noindex` (no canonical => the layout prints the robots meta).
 *
 * VIEW `storefront.cart` receives EXACTLY:
 *   categories     list<App\Storefront\ReadModels\CategoryNode>  the menu
 *   seo            array{title: string, description: string, canonical: null}
 *   priceTemplate  array{template: string, zero: string, currency: string}  how the shop writes a price: the default
 *                  currency's zero formatted by PriceFormatter ("0.00 €"), the zero itself ("0.00") and the currency
 *                  code. The script swaps `zero` for a real amount, so the cart prints prices exactly as the
 *                  server-rendered pages do (symbol, position, decimals) with display formatting only.
 */
final class CartPageController extends Controller
{
    public function show(CatalogReader $reader, PriceFormatter $prices): Response
    {
        $currency = DefaultCurrency::get();
        $template = $prices->format(0, $currency->code());
        $zero = $currency->decimalPlaces() > 0 ? '0.'.str_repeat('0', $currency->decimalPlaces()) : '0';

        return response()
            ->view('storefront.cart', [
                'categories' => $reader->categoryTree(),
                'seo' => [
                    'title' => __('storefront.cart_title').' — '.config('app.name'),
                    'description' => '',
                    'canonical' => null,
                ],
                'priceTemplate' => ['template' => $template, 'zero' => $zero, 'currency' => $currency->code()],
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex');
    }
}
