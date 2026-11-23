<?php

namespace Tests\Feature\Cookies;

use PHPUnit\Framework\TestCase;

/**
 * CC-1: the invariants of `resources/js/storefront-consent.js` that a PHP run can pin from its SOURCE (the behaviour itself is measured in a real browser: tests/Browser/cc-1.mjs):
 * the cookie is written only by `writeCookie()`, which only a click handler path (`decide()`) calls; the version is compared; nothing is loaded, fetched or sent; there is no Escape handling;
 * the public API is exactly the documented one.
 */
class CookieScriptTest extends TestCase
{
    private string $js;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 3).'/resources/js/storefront-consent.js';
        $this->assertFileExists($path);
        // comments are not code
        $this->js = (string) preg_replace(['#/\*.*?\*/#s', '#(?<!:)//[^\n]*#'], '', (string) file_get_contents($path));
    }

    /** ★ the cookie is written in ONE place, and only decide() (a click) calls that place: nothing is written on load */
    public function test_the_cookie_is_written_only_after_a_click(): void
    {
        $this->assertSame(1, substr_count($this->js, 'document.cookie ='), 'one write');
        $this->assertSame(2, substr_count($this->js, 'writeCookie('), 'its definition and ONE call');
        $this->assertSame(1, preg_match('/function decide\(ads\) \{\s*choice = writeCookie\(ads\);/', $this->js), 'the one call is decide()');

        // decide() is reached only from the three choice buttons
        $this->assertSame(4, substr_count($this->js, 'decide('), 'the definition and the accept / reject / save cases');
        $this->assertSame(1, preg_match("/case 'accept':\s*decide\(true\);/", $this->js));
        $this->assertSame(1, preg_match("/case 'reject':\s*decide\(false\);/", $this->js));
        $this->assertSame(1, preg_match("/case 'save':\s*decide\(/", $this->js));
    }

    /** ★ a cookie with another version (or a damaged value) is «no choice» */
    public function test_the_version_is_compared_and_a_damaged_value_is_no_choice(): void
    {
        $this->assertStringContainsString("params.get('v') !== version", $this->js);
        $this->assertStringContainsString("(ads !== '0' && ads !== '1')", $this->js);
        $this->assertSame(1, preg_match('#/\^\\\\d\{1,12\}\$/\.test\(time\)#', $this->js), 'the time must be digits');
    }

    /** nothing optional is loaded, fetched, sent or stored besides the one cookie */
    public function test_nothing_is_loaded_fetched_or_sent(): void
    {
        foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'import(', "createElement('script')", 'createElement("script")', 'localStorage', 'sessionStorage', 'indexedDB', 'WebSocket', 'new Image', 'eval(', 'innerHTML', 'document.write'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->js, $forbidden);
        }

        $this->assertSame(0, preg_match('#https?://#', $this->js), 'no address of any kind');
    }

    /** closing without a choice is not consent: no Escape handling and no close action */
    public function test_there_is_no_escape_handling_and_no_close_action(): void
    {
        $this->assertStringNotContainsString('Escape', $this->js);
        $this->assertStringNotContainsString("case 'close'", $this->js);
        $this->assertStringNotContainsString('Esc', $this->js);
    }

    /** the documented API and event, nothing more */
    public function test_the_public_api_is_exactly_the_documented_one(): void
    {
        $this->assertSame(1, preg_match('/window\.EasyCoConsent = Object\.freeze\(\{\s*get:.*?has:.*?open:.*?onChange:/s', $this->js));
        $this->assertSame(1, substr_count($this->js, 'window.EasyCoConsent ='));
        $this->assertSame(0, preg_match('/window\.(?!EasyCoConsent)\w+\s*=/', $this->js), 'no other global');
        $this->assertStringContainsString("new CustomEvent('easyco:consent'", $this->js);
        $this->assertStringContainsString('Path=/', $this->js);
        $this->assertStringContainsString('SameSite=Lax', $this->js);
        $this->assertStringContainsString("'; Secure'", $this->js);
        $this->assertStringNotContainsString('HttpOnly', $this->js);
        $this->assertStringContainsString('months * 30 * 86400', $this->js);
    }
}
