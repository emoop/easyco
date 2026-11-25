<?php

namespace Tests\Feature\Cookies;

use PHPUnit\Framework\TestCase;

/**
 * CC-1: the invariants of `resources/js/storefront-consent.js` that a PHP run can pin from its SOURCE (the behaviour itself is measured in a real browser: tests/Browser/cc-1.mjs):
 * the cookie is written only by `writeCookie()`, which only a click handler path (`decide()`) calls; the version is compared; nothing is loaded, fetched or sent;
 * the public API is exactly the documented one. CC-2: «×» and Escape close without a choice; «Отказвам» is the all-off save; the policy page's bar is not modal.
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

    /** ★ CC-2: «×» and Escape close WITHOUT a choice: they call close() and nothing else; the one cookie write stays in decide() */
    public function test_the_close_button_and_escape_close_without_a_choice(): void
    {
        $this->assertSame(1, preg_match("/case 'dismiss':\s*(?:\/\/[^\n]*\n\s*)?close\(\);\s*break;/", $this->js), 'the «×» action only closes');
        $this->assertSame(1, preg_match("/event\.key === 'Escape' && ! root\.hidden\) \{\s*event\.preventDefault\(\);\s*close\(\);\s*return;/", $this->js), 'Escape is the «×»');

        // no storage is written for a close: still ONE cookie write (in decide()), no localStorage / sessionStorage / second cookie
        $this->assertSame(1, substr_count($this->js, 'document.cookie ='));
        $this->assertStringNotContainsString('localStorage', $this->js);
        $this->assertStringNotContainsString('sessionStorage', $this->js);
    }

    /** ★ CC-2: «Отказвам» is the SAME save as «Настройки → Запази» with every switch off: both end in decide(false), through the one cookie write */
    public function test_reject_is_the_settings_save_with_everything_off(): void
    {
        $this->assertSame(1, preg_match("/case 'reject':\s*decide\(false\);/", $this->js));
        $this->assertSame(1, preg_match("/case 'save':\s*decide\(switches\.some\(\(toggle\) => toggle\.dataset\.sfCcSwitch === 'ads' && toggle\.getAttribute\('aria-checked'\) === 'true'\)\);/", $this->js), 'a save with every switch off decides false');
        $this->assertSame(2, substr_count($this->js, 'writeCookie('), 'no second save path');
    }

    /** ★ CC-2: on the policy page the banner is a thin NON-modal bar: no lock, no inert page, no focus trap; the page keeps the bar's height as bottom padding */
    public function test_the_policy_page_bar_is_not_modal_and_the_page_keeps_its_height(): void
    {
        $this->assertStringContainsString("root.dataset.policyPage === '1'", $this->js, 'the marker the server prints');
        $this->assertSame(1, preg_match('/function isModal\(\) \{\s*return modal && ! barMode;/', $this->js));
        $this->assertSame(1, preg_match('/function lockPage\(\) \{\s*if \(! isModal\(\)\) \{/', $this->js), 'no scroll lock and no inert page in the bar');
        $this->assertStringContainsString("event.key !== 'Tab' || ! isModal()", $this->js, 'no focus trap in the bar');
        $this->assertStringContainsString('--sf-cc-bar-h', $this->js);
        $this->assertStringContainsString("open(initialView, false, policyPage && initialView === 'summary')", $this->js, 'only the automatic opening of a visitor with no choice');
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
