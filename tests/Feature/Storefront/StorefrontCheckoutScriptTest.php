<?php

namespace Tests\Feature\Storefront;

use PHPUnit\Framework\TestCase;

/**
 * Static guards on the storefront checkout script (there is no JavaScript test runner in this project): it never writes API
 * data as markup, it only calls the endpoints the checkout is allowed to, it follows the cookie gate, it never submits twice
 * and it only navigates to the order-received page of its own site.
 */
class StorefrontCheckoutScriptTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront-checkout.js');
    }

    private function code(): string
    {
        return (string) preg_replace('#//[^\n]*#', '', $this->script());
    }

    public function test_it_never_turns_api_data_into_markup(): void
    {
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'DOMParser', 'createContextualFragment', 'setTimeout("'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->code(), $forbidden);
        }

        // Urls and ids are set with setAttribute after being built from our own values; never by property assignment.
        $this->assertDoesNotMatchRegularExpression('/\.(href|src|srcset)\s*=[^=]/', $this->code());
    }

    public function test_it_only_calls_the_cart_the_quote_and_the_checkout_endpoints(): void
    {
        preg_match_all("/api\\(\\s*'([A-Z]+)',\\s*'([^']+)'/", $this->script(), $calls, PREG_SET_ORDER);

        $seen = array_unique(array_map(fn (array $c) => $c[1].' '.$c[2], $calls));
        sort($seen);

        $this->assertSame(['GET /api/cart', 'POST /api/checkout', 'POST /api/shipping/quote'], $seen);
        $this->assertStringNotContainsString('sanctum', $this->script(), 'the CSRF cookie handling lives in storefront-cart.js, once');
    }

    public function test_the_cookie_gate_is_the_shared_one_and_a_visitor_without_a_cart_is_sent_to_the_cart_page(): void
    {
        $code = $this->code();

        $this->assertMatchesRegularExpression('/import \{[^}]*\bhasCartCookie\b[^}]*\} from \'\.\/storefront-cart\.js\'/', $code);
        $this->assertMatchesRegularExpression('/async function loadCart\(\) \{\s*if \(! hasCartCookie\(\)\) \{\s*window\.location\.replace\(cartUrl\);/', $code);
        $this->assertStringContainsString('result.data.lines.length === 0', $code, 'an empty cart goes to /cart too');
        $this->assertStringNotContainsString('readCookie', $code, 'no second implementation of the gate');
    }

    public function test_a_second_submit_is_impossible_and_the_button_is_locked(): void
    {
        $code = $this->code();

        $this->assertMatchesRegularExpression('/if \(submitting \|\| cart === null \|\| paymentLocked \|\| chosenPayment\(\) === null\) \{\s*return;/', $code);
        // C2-B (cod-protection-design.md 4): and locked while every payment method was refused (a block) or none is chosen.
        // C1b (A7): also locked until the price is FINAL (not a preview) and while a pick-up point's fields are empty.
        $this->assertStringContainsString('placeOrder.disabled = submitting || promoBusy || termsStale || paymentLocked || chosenPayment() === null || cart === null || selection === null || quotePending || ! selection.final || (isPickupKind(selection.kind) && ! pointFieldsFilled());', $code);
        $this->assertMatchesRegularExpression('/submitting = true;\s*refreshPlaceOrder\(\);/', $code);
        $this->assertStringContainsString('keepLocked = true;', $code, 'after a success the button stays locked while the page navigates');
    }

    public function test_it_navigates_only_to_the_order_received_page_of_its_own_site(): void
    {
        $code = $this->code();

        $this->assertStringContainsString("url.origin === window.location.origin && url.pathname.indexOf('/order/received/') === 0", $code);
        $this->assertStringContainsString('window.location.replace(target)', $code);
        $this->assertSame(1, substr_count($code, 'window.location.replace(target)'));
    }

    public function test_the_quote_is_debounced_one_at_a_time_and_a_stale_answer_is_ignored(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('var DEBOUNCE_MS = 500;', $code);
        $this->assertStringContainsString('window.clearTimeout(timer);', $code);
        $this->assertMatchesRegularExpression('/if \(quoteInFlight\) \{\s*(\/\/[^\n]*\s*)?quoteDirty = true;\s*return;/', $this->script());
        $this->assertMatchesRegularExpression('/if \(quoteDirty\) \{\s*quoteDirty = false;\s*runQuote\(\);\s*return;/', $code, 'the answer that was running when the destination moved on is dropped');
    }

    public function test_it_sends_the_sandbox_payload_shape(): void
    {
        $code = $this->code();

        foreach (['cart_id: cartId', 'shipping_method_id: selection.id', 'quote_handle: selection.handle', 'expected_shipping_minor: selection.priceMinor', 'delivery_type: deliveryType()', 'payment_method:'] as $needle) {
            $this->assertStringContainsString($needle, $code, $needle);
        }
    }

    public function test_field_errors_go_next_to_the_fields_and_the_summary_takes_focus(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('errorSummary.focus();', $code);
        $this->assertStringContainsString("input.setAttribute('aria-invalid', 'true');", $code);
        $this->assertStringContainsString("input.setAttribute('aria-describedby', holder.id + (input.getAttribute('data-sf-describedby') ? ' ' + input.getAttribute('data-sf-describedby') : ''));", $code, 'the error id joins the own hint of the field instead of replacing it');
        $this->assertStringContainsString('result.data.errors', $code);
    }

    public function test_the_courier_fills_carrier_code_and_no_typed_courier_field_is_read(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('body.carrier_code = selection.carrier;', $code);
        $this->assertStringContainsString('carrier: courier.name === null ? option.method.name : courier.name,', $code, 'the chosen courier; a courier-less method names itself because the API requires the field');
        $this->assertStringNotContainsString('destination_kind', $code);
        $this->assertStringNotContainsString("fieldValue('carrier_code')", $code, 'carrier_code is never read from a form field');
        $this->assertStringNotContainsString("fieldValue('settlement')", $code);
        $this->assertStringContainsString("body.settlement = fieldValue('city');", $code, "the one place field is the pick-up point's settlement");
    }

    public function test_the_destination_is_quoted_once_for_every_kind_with_one_all_kinds_request(): void
    {
        $code = $this->code();

        // ONE quote body, the all-kinds one; the two legacy single-kind quotes (street, pick-up) are gone.
        $this->assertSame(1, substr_count($code, "'/api/shipping/quote'"), 'one quote call');
        $this->assertStringContainsString("var body = { delivery_type: 'all', country: country };", $code);
        $this->assertStringContainsString('body.settlement = place;', $code, 'no place = a preview; a place = the final answer');
        $this->assertStringNotContainsString("delivery_type: 'street_address'", $code);
        $this->assertStringNotContainsString("delivery_type: 'pickup_point'", $code);
        $this->assertStringNotContainsString('quoteBodies', $code);
        $this->assertStringNotContainsString('buildCouriers(streetData, pickupData)', $code);
        $this->assertStringNotContainsString('pickup_kind', $code, 'the API prohibits a pick-up kind together with `all`');
        $this->assertStringContainsString('buildCouriers(data)', $code);
        $this->assertStringContainsString('data.groups', $code, 'the courier groups of the quote are what the choice is built from');
        $this->assertStringContainsString('method.destinations', $code, 'the kinds of a method come from its `destinations`');
    }

    public function test_the_payload_carries_the_kind_the_region_and_the_note_under_the_names_the_api_reads(): void
    {
        $code = $this->code();

        foreach ([
            "return isPickupKind(kind) ? 'pickup_point' : 'street_address';",
            'body.pickup_point_kind = selection.kind;',
            'body.region = fieldValue(\'region\');',
            'body.customer_note = note;',
            'body.carrier_code = selection.carrier;',
        ] as $needle) {
            $this->assertStringContainsString($needle, $code, $needle);
        }

        // The rule: a hidden region is never sent; an empty note is omitted.
        $this->assertStringContainsString("regionMode() !== 'hidden' && fieldValue('region') !== ''", $code);
        $this->assertStringContainsString("if (note.trim() !== '') {", $code);
    }

    public function test_rule_a_a_changed_price_is_never_resubmitted_by_the_script(): void
    {
        $code = $this->code();

        // `submit` is called by the browser's submit event only: never from the refusal handling.
        $this->assertSame(1, substr_count($code, "form.addEventListener('submit', submit);"));
        $this->assertSame(1, substr_count($code, "api('POST', '/api/checkout'"), 'one call site for the order');
        $this->assertStringContainsString('adoptFreshPrice(price);', $code);
        // ...and exactly ONE automatic quote follows (the handle is bound to the old amount), once per refusal site.
        $this->assertMatchesRegularExpression('/adoptFreshPrice\(price\);\s*selection\.handle = null;\s*scheduleQuote\(true\);/', $code);
        $this->assertMatchesRegularExpression('/function adoptFreshPrice\(price\) \{\s*selection\.priceMinor = price\.minor;/', $code);
    }

    public function test_the_promo_box_is_the_carts_own_and_the_checkout_adds_no_second_implementation_or_endpoint(): void
    {
        $code = $this->code();
        $cart = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront-cart.js');

        $this->assertStringContainsString("message, promotionBox, setCount, t } from './storefront-cart.js';", $code);
        $this->assertStringContainsString('promotionBox(cart, runPromo, { compact: true })', $code);
        $this->assertStringContainsString('export { api, apiMessage, el, formatMoney, hasCartCookie, lineImage, localUrl, message, promotionBox, setCount, t };', $cart);
        $this->assertStringContainsString('summary.appendChild(promotionBox(cart, act, { compact: true }));', $cart, 'the cart page uses the same function');
        $this->assertSame(1, substr_count($cart, 'function promotionBox('), 'ONE implementation');

        foreach (['sf-cart-promo', 'promo_label', "'/api/cart/promotion'", 'promo_apply'] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "the checkout does not rebuild the box ({$needle})");
        }
    }

    public function test_the_payload_has_the_two_names_and_only_the_one_pickup_field(): void
    {
        $code = $this->code();

        $this->assertStringContainsString("recipient_first_name: fieldValue('recipient_first_name'),", $code);
        $this->assertStringContainsString("recipient_last_name: fieldValue('recipient_last_name'),", $code);
        $this->assertStringContainsString("var PICKUP_FIELDS = ['pickup_point_address'];", $code);

        foreach (['recipient_name', 'pickup_point_reference', 'pickup_point_name'] as $gone) {
            $this->assertStringNotContainsString($gone, $code, "{$gone} is not sent or read any more");
        }
    }

    public function test_one_function_toggles_required_aria_required_and_the_asterisk(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('function setRequired(name, on) {', $code);
        $this->assertStringContainsString("input.setAttribute('aria-required', 'true');", $code);
        $this->assertStringContainsString("setRequired('address_line_1', street);", $code);
        $this->assertStringContainsString("setRequired('region', street && mode === 'required');", $code);
        $this->assertStringContainsString('setRequired(name, pickup);', $code);
        $this->assertSame(1, substr_count($code, '.required = '), '`required` is assigned in ONE place, inside setRequired: it never changes on its own');
        $this->assertStringNotContainsString('data-required', $code, 'the CSS trick of C1b is replaced by the real marker');
    }

    public function test_the_note_counts_code_points_and_the_region_rule_is_exported_for_the_agreement_test(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('return Array.from(String(text));', $code, 'code points, not UTF-16 units');
        $this->assertStringContainsString('export { regionModeFor };', $code);
        $this->assertStringContainsString('Object.prototype.hasOwnProperty.call(countries, code)', $code, 'a country code is never a property lookup on a plain object');
    }

    public function test_the_summary_toggle_is_wired_to_aria_expanded_and_the_panel(): void
    {
        $code = $this->code();

        $this->assertStringContainsString("summaryToggle.setAttribute('aria-expanded', open ? 'true' : 'false');", $code);
        $this->assertStringContainsString("summaryPanel.setAttribute('data-open', open ? 'true' : 'false');", $code);
        $this->assertStringContainsString('summaryToggleTotal.textContent = grand;', $code, 'the bar total follows the delivery choice');
    }

    public function test_every_text_key_it_asks_for_exists_in_both_languages(): void
    {
        preg_match_all("/\\bt\\('([a-z_]+)'/", $this->script(), $matches);
        preg_match_all("/'(ck_[a-z_]+)'/", $this->script(), $literal);
        $keys = array_unique(array_merge($matches[1], $literal[1]));

        foreach (['en', 'bg'] as $locale) {
            $lang = require dirname(__DIR__, 3)."/lang/{$locale}/storefront.php";
            $available = array_merge(array_keys($lang['js']), array_keys($lang['js_checkout']));

            $this->assertSame([], array_values(array_diff($keys, $available)), "{$locale}: a text key the checkout script asks for is missing");
        }
    }

    public function test_the_cart_page_links_to_the_checkout_instead_of_a_disabled_button(): void
    {
        $cart = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront-cart.js');

        $this->assertStringContainsString("var checkout = el('a', 'sf-cart-checkout', t('checkout_label'));", $cart);
        $this->assertStringNotContainsString('checkout.disabled = true', $cart);
        $this->assertStringContainsString("data-checkout-url", $cart);
    }
}
