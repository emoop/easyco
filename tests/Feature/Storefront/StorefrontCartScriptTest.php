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

    public function test_the_header_count_asks_the_api_only_when_an_xsrf_cookie_exists(): void
    {
        $script = $this->script();

        $this->assertMatchesRegularExpression("/async function refreshCount\\(\\) \\{\\s*if \\(! readCookie\\(XSRF_COOKIE\\)\\) \\{\\s*return;/", $script);
        $this->assertMatchesRegularExpression("/async function start\\(\\) \\{\\s*if \\(! readCookie\\(XSRF_COOKIE\\)\\) \\{/", $script, 'the cart page too (R1)');
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
}
