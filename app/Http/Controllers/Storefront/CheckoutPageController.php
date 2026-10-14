<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Settings\CheckoutFormSettings;
use App\Settings\CheckoutRegionPolicy;
use App\Settings\CountryNames;
use App\Settings\InvoiceSettings;
use App\Settings\StoreCountry;
use App\Settings\StoreLocale;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Support\PriceFormatter;
use EasyCo\Extensibility\Hook;
use EasyCo\Payment\Registry\PaymentAvailabilityContext;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
use EasyCo\Pricing\DefaultCurrency;
use Illuminate\Http\Response;
use Illuminate\Support\HtmlString;

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
 *   paymentCards    list<array{code: string, label: string, description: ?string, icon: ?string, placeOrderLabel: ?string, flow: string}>
 *                   one per payment method the PaymentMethodRegistry says is available for the page's context (store country, default
 *                   currency, total unknown): the API asks the same registry again with the real order. Plain strings: never HTML.
 *   defaultCountry  string  the store's own country code (BG when none is set)
 *   countries       array<string, string>  ISO code => name in the page's locale, sorted by that locale (the country <select>'s options)
 *   form            array{callBeforeShipping: bool, newsletter: bool, termsShown: bool, termsVersion: string, privacyHtml: ?HtmlString,
 *                   termsHtml: ?HtmlString}  the optional choices and consent (checkout form v2, section 6): the two sentences with their link
 *                   are built here as ESCAPED anchors (the merchant's URL is checked and escaped, never rendered as markup)
 *   invoice         array{enabled: bool, rules: array<string, array<string, mixed>>}  whether the shop offers an invoice (invoice.enabled, ON unless
 *                   '0') and the per-country rules as the page needs them (labels and requirements, NEVER a format: the server is its only judge),
 *                   written into a JSON data attribute; both come from the page's ONE settings read (order-context-design.md 9.3)
 *   expressPartials list<string>  what extensions contribute to the express-checkout slot (filter `storefront.checkout.express`); empty = not rendered
 *   regionPolicy    array{default: string, countries: array<string, string>}  CheckoutRegionPolicy::all(), written into the page as a
 *                   JSON data attribute (an empty list of exceptions is `{}`); the script resolves the region mode of the current country
 */
final class CheckoutPageController extends Controller
{
    public function show(CatalogReader $reader, PriceFormatter $prices, PaymentMethodRegistry $payments, StoreCountry $country, CheckoutRegionPolicy $regions, CheckoutFormSettings $settings, StoreLocale $storeLocale, InvoiceSettings $invoice): Response
    {
        $currency = DefaultCurrency::get();
        $zero = $currency->decimalPlaces() > 0 ? '0.'.str_repeat('0', $currency->decimalPlaces()) : '0';

        $locale = $storeLocale->current();
        $storeCountry = $country->currentOrNull() ?? 'BG';

        // The page does not know the cart or the delivery yet (it is a cookie-free shell): it asks with the store's country and currency
        // and an unknown total. A method whose availability depends on more is refused by the API for the real order.
        $cards = array_map(static fn ($descriptor): array => [
            'code' => $descriptor->code,
            'label' => $descriptor->label($locale),
            'description' => $descriptor->description($locale),
            // A logo is an <img src>: only an http(s) URL or a same-site path is ever written there.
            'icon' => $descriptor->icon !== null && CheckoutFormSettings::isSafeLink($descriptor->icon) ? $descriptor->icon : null,
            'placeOrderLabel' => $descriptor->placeOrderLabel($locale),
            'flow' => $descriptor->flow->value,
        ], $payments->available(new PaymentAvailabilityContext($storeCountry, $currency->code(), 0, null)));

        $termsUrl = $settings->termsRequired() ? $settings->safeTermsUrl() : null;
        $privacyUrl = $settings->safePrivacyUrl();

        return response()
            ->view('storefront.checkout', [
                'categories' => $reader->categoryTree(),
                'seo' => [
                    'title' => __('storefront.checkout_title').' — '.config('app.name'),
                    'description' => '',
                    'canonical' => null,
                ],
                'priceTemplate' => ['template' => $prices->format(0, $currency->code()), 'zero' => $zero, 'currency' => $currency->code()],
                'paymentCards' => $cards,
                'defaultCountry' => $storeCountry,
                'countries' => CountryNames::forLocale($locale),
                'form' => [
                    'callBeforeShipping' => $settings->callBeforeShippingEnabled(),
                    'newsletter' => $settings->newsletterEnabled(),
                    'termsShown' => $termsUrl !== null,
                    'termsVersion' => $settings->currentTermsVersion(),
                    'privacyHtml' => $privacyUrl === null ? null : $this->sentenceWithLink('storefront.checkout_privacy_notice', 'storefront.checkout_privacy_link', $privacyUrl, $locale),
                    'termsHtml' => $termsUrl === null ? null : $this->sentenceWithLink('storefront.checkout_terms_sentence', 'storefront.checkout_terms_link', $termsUrl, $locale),
                ],
                // An extension fills the express-checkout slot (one-click wallets) with partial names; nothing is rendered when it is empty.
                'expressPartials' => array_values(array_filter((array) Hook::apply('storefront.checkout.express', []), static fn ($name): bool => is_string($name) && $name !== '' && view()->exists($name))),
                'regionPolicy' => $regions->all(),
                'invoice' => ['enabled' => $invoice->enabled(), 'rules' => $invoice->enabled() ? $invoice->forPage() : []],
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * A lang sentence with a `:link` placeholder, as HTML built here from ESCAPED parts only: the sentence and the link text come from the
     * lang file, the URL from the merchant's setting (already refused unless it is an http(s) URL or a same-site path), every one of them
     * through e(). The link opens in a new tab with `rel="noopener"`, so the half-filled form is not lost.
     */
    private function sentenceWithLink(string $sentenceKey, string $linkKey, string $url, string $locale): HtmlString
    {
        $anchor = '<a href="'.e($url).'" target="_blank" rel="noopener">'.e((string) __($linkKey, [], $locale)).'</a>';

        return new HtmlString(str_replace(':link', $anchor, e((string) __($sentenceKey, [], $locale))));
    }
}
