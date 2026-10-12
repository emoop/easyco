<?php

namespace Tests\Feature\Storefront;

use PHPUnit\Framework\TestCase;

/**
 * Static guards on the storefront cart script (there is no JavaScript test runner in this project): it never writes
 * API data as markup, it only calls the endpoints the storefront is allowed to, and every text key it asks for exists
 * in both languages.
 */
class StorefrontCartScriptTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront-cart.js');
    }

    public function test_it_never_turns_api_data_into_markup(): void
    {
        $code = (string) preg_replace('#//[^\n]*#', '', $this->script());

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setTimeout("', 'DOMParser', 'createContextualFragment'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, $forbidden);
        }
    }

    public function test_it_only_calls_the_cart_api_and_the_csrf_cookie_endpoint(): void
    {
        preg_match_all("/api\\(\\s*'([A-Z]+)',\\s*'([^']+)'/", $this->script(), $calls, PREG_SET_ORDER);

        $seen = array_map(fn (array $call) => $call[1].' '.preg_replace('#/api/cart/lines/.*#', '/api/cart/lines/{id}', $call[2]), $calls);
        $allowed = ['GET /api/cart', 'POST /api/cart/lines', 'PATCH /api/cart/lines/{id}', 'DELETE /api/cart/lines/{id}', 'PUT /api/cart/promotion', 'DELETE /api/cart/promotion'];

        $this->assertNotEmpty($seen);
        $this->assertSame([], array_values(array_diff(array_unique($seen), $allowed)));
        $this->assertSame(1, substr_count($this->script(), "'/sanctum/csrf-cookie'"));
    }

    public function test_it_sends_the_xsrf_header_the_credentials_mode_and_never_a_cart_token(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("'X-XSRF-TOKEN'", $script);
        $this->assertStringContainsString("credentials: 'same-origin'", $script);
        $this->assertStringContainsString("'X-Requested-With'", $script);
        $this->assertStringContainsString("'Accept'", $script);
        $this->assertDoesNotMatchRegularExpression('/cart[_-]?token|localStorage|sessionStorage/i', $script);
    }

    public function test_the_header_count_and_the_cart_page_ask_the_api_only_when_a_cart_cookie_exists(): void
    {
        $script = $this->script();

        $this->assertMatchesRegularExpression("/async function refreshCount\\(\\) \\{\\s*if \\(! hasCartCookie\\(\\)\\) \\{\\s*return;/", $script);
        $this->assertMatchesRegularExpression("/async function start\\(\\) \\{\\s*if \\(! hasCartCookie\\(\\)\\) \\{/", $script, 'the cart page too (R1)');
    }

    public function test_the_gate_is_exactly_the_xsrf_cookie_or_the_marker_and_nothing_else(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("var XSRF_COOKIE = 'XSRF-TOKEN';", $script);
        $this->assertStringContainsString("var CART_MARKER_COOKIE = 'easyco_has_cart';", $script);
        $this->assertMatchesRegularExpression('/function hasCartCookie\(\) \{\s*return Boolean\(readCookie\(XSRF_COOKIE\) \|\| readCookie\(CART_MARKER_COOKIE\)\);\s*\}/', $script);
        $this->assertStringNotContainsString("'easyco_cart'", $script, 'the HttpOnly token cookie is never named by the script: it cannot see it');
    }

    public function test_no_api_request_is_made_outside_the_gate(): void
    {
        $script = $this->script();

        // The read calls (the header count's and the cart page's first load) are each behind the gate...
        $this->assertSame(2, preg_match_all("/if \(! hasCartCookie\(\)\) \{/", $script));

        // ... and GET /api/cart is requested from exactly four places: those two, and the cart page's own reload()/act() after a
        // write the visitor made (a write needs the csrf-cookie request first, which is the only request without a cookie).
        preg_match_all("/api\('GET', '\/api\/cart'\)/", $script, $gets);
        $this->assertCount(3, $gets[0]);
        $this->assertSame(1, substr_count($script, "'/sanctum/csrf-cookie'"));
    }

    public function test_every_text_key_the_script_asks_for_exists_in_both_languages(): void
    {
        preg_match_all("/\\bt\\('([a-z_]+)'/", $this->script(), $matches);
        $keys = array_unique($matches[1]);

        foreach (['en', 'bg'] as $locale) {
            $lang = require dirname(__DIR__, 3)."/lang/{$locale}/storefront.php";
            $available = array_merge(array_keys($lang['js']), ['cart_count_label']);

            $this->assertSame([], array_values(array_diff($keys, $available)), "{$locale}: a text key the script asks for is missing");
        }
    }

    public function test_the_line_image_and_link_come_only_from_checked_values_through_setattribute(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('localUrl(line.product_url)', $script, 'the product link is root-relative or nothing');
        $this->assertStringContainsString("setAttribute('href', href)", $script);
        $this->assertStringContainsString("node.setAttribute('loading', 'lazy')", $script);
        $this->assertStringContainsString("node.setAttribute('decoding', 'async')", $script);
        $this->assertStringContainsString("node.setAttribute('alt',", $script);
        $this->assertDoesNotMatchRegularExpression('/\.(href|src|srcset)\s*=[^=]/', preg_replace('#//[^
]*#', '', $script), 'urls are set with setAttribute, never by property assignment');
    }
}
