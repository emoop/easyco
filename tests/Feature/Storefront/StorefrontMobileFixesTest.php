<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Stage S3-V2 (three fixes found on a phone at 320px), as far as a suite without a browser can pin them: the guard against hiding sideways scroll, the
 * checkout summary's PEEK (CSS and script source; its behaviour is the fake-DOM scenario `summary_peek_access`) and the bare hamburger. The real
 * overflow check is a browser script: tests/Feature/Storefront/Js/overflow-check.mjs (how to run it is in its header).
 */
class StorefrontMobileFixesTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        $this->seedPricing();
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$this->makeCategory(1, 'Clothing', 'clothing')], 'price' => '10.00', 'stock' => 3]);
    }

    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css');
    }

    private function script(): string
    {
        return (string) preg_replace('#//[^\n]*#', '', (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront-checkout.js'));
    }

    // ---- fix 1: no sideways scroll ---------------------------------------------------------------------------------------------------

    public function test_nothing_hides_sideways_scroll_on_the_page_roots(): void
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->css(), $rules, PREG_SET_ORDER);

        foreach ($rules as [, $selector, $body]) {
            if (preg_match('/overflow(-x)?\s*:\s*(hidden|clip)/', $body) !== 1) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/(^|[\s,>+~])(html|body)\b|\.sf-layout\b/', trim($selector), "`overflow-x: hidden` would only MASK an overflow (found on: {$selector})");
        }
    }

    public function test_the_grids_that_hold_long_content_have_shrinkable_tracks_and_long_words_wrap(): void
    {
        $css = $this->css();

        foreach (['.sf-cart-layout', '.sf-checkout-layout'] as $grid) {
            $this->assertMatchesRegularExpression('/'.preg_quote($grid, '/').' \{\s*grid-template-columns: minmax\(0, 1fr\);/', $css, "{$grid}: the track may shrink below its widest word");
        }

        // ...but ONLY below the two-column breakpoint: an unconditional rule later in the file would flatten the desktop columns (it once did, S3-V2).
        $this->assertMatchesRegularExpression('/@media \(max-width: 56\.249rem\) \{\s*\.sf-cart-layout \{\s*grid-template-columns: minmax\(0, 1fr\);/', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 63\.999rem\) \{\s*\.sf-checkout-layout \{\s*grid-template-columns: minmax\(0, 1fr\);/', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 56\.25rem\) \{\s*\.sf-cart-layout \{\s*grid-template-columns: minmax\(0, 1fr\) 22rem;/', $css, 'the cart keeps its second column from 900px');
        $this->assertMatchesRegularExpression('/@media \(min-width: 64rem\) \{\s*\.sf-checkout-layout \{\s*grid-template-columns: minmax\(0, 1fr\) 24rem;/', $css, 'and the checkout its summary column from 1024px');

        $this->assertMatchesRegularExpression('/\.sf-header-bar \{\s*grid-template-columns: minmax\(0, 1fr\) auto minmax\(0, 1fr\);/', $css);
        $this->assertMatchesRegularExpression('/\.sf-title,[^{]*\.sf-description[^{]*\{\s*overflow-wrap: anywhere;/', $css);
        $this->assertMatchesRegularExpression('/\.sf-summary-line-info,[^{]*\{\s*overflow-wrap: anywhere;/', $css);
    }

    public function test_the_overflow_checker_is_in_the_repo_and_says_how_to_run_it(): void
    {
        $file = (string) file_get_contents(__DIR__.'/Js/overflow-check.mjs');

        $this->assertStringContainsString('PLAYWRIGHT_DIR=', $file);
        $this->assertStringContainsString('no dependency is added', strtolower($file));
        $this->assertStringContainsString('getBoundingClientRect', $file);
    }

    // ---- fix 2: the peek -------------------------------------------------------------------------------------------------------------

    public function test_the_collapsed_summary_is_not_display_none_below_1024px_except_where_has_is_missing(): void
    {
        $css = $this->css();

        // The only `display: none` of a collapsed summary left is the one in the @supports-not fallback.
        preg_match_all("/\\.sf-checkout-summary\\[data-open='false'\\]\\s*\\{[^}]*display:\\s*none;/", $css, $matches, PREG_OFFSET_CAPTURE);
        $this->assertCount(1, $matches[0], 'one rule, the fallback');
        $before = substr($css, (int) strrpos(substr($css, 0, $matches[0][0][1]), '@supports'), 40);
        $this->assertStringContainsString('@supports not selector(:has(*))', $before);

        $this->assertMatchesRegularExpression("/@supports selector\\(:has\\(\\*\\)\\) \\{\\s*\\.sf-checkout-summary\\[data-open='false'\\] \\{\\s*display: block;/", $css, 'the peek');
    }

    public function test_the_peek_clips_the_list_fades_it_with_an_overlay_and_hides_the_rest(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression("/\\.sf-checkout-summary\\[data-open='false'\\] \\.sf-summary-lines \\{[^}]*max-height: 4\\.5rem;[^}]*overflow: hidden;/s", $css);
        $this->assertMatchesRegularExpression("/\\.sf-summary-lines::after \\{[^}]*height: 3rem;[^}]*linear-gradient\\(to bottom, transparent, #fff\\);[^}]*pointer-events: none;/s", $css, 'an overlay, not a mask or a blur');
        $this->assertStringNotContainsString('mask-image', $css);
        $this->assertDoesNotMatchRegularExpression('/\bblur\(/', $css);
        $this->assertMatchesRegularExpression("/\\.sf-checkout-summary\\[data-open='false'\\] > :not\\(\\.sf-summary-lines\\) \\{\\s*display: none;/", $css, 'title, promo, error and totals are hidden');
        $this->assertMatchesRegularExpression("/\\.sf-checkout-summary\\[data-open='false'\\]:has\\(\\.sf-summary-lines:empty\\) \\{\\s*display: none;/", $css, 'no empty white strip');
    }

    public function test_the_bar_is_still_hidden_from_1024px_and_has_a_css_chevron(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/@media \(min-width: 64rem\) \{.*?\.sf-summary-toggle \{\s*display: none;/s', $css);
        $this->assertMatchesRegularExpression("/\\.sf-summary-toggle\\[aria-expanded='true'\\]::after \\{[^}]*rotate\\(-135deg\\)/s", $css);
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.sf-summary-toggle::after \{\s*transition: none;/', $css);
    }

    public function test_the_peek_tap_runs_the_bars_own_function_and_only_while_collapsed(): void
    {
        $code = $this->script();

        $this->assertSame(1, substr_count($code, 'function toggleSummary() {'), 'ONE toggle function');
        $this->assertStringContainsString("summaryToggle.addEventListener('click', toggleSummary);", $code, 'the bar');
        $this->assertMatchesRegularExpression("/summaryPanel\\.addEventListener\\('click', function \\(\\) \\{\\s*if \\(summaryPanel\\.getAttribute\\('data-open'\\) === 'false'\\) \\{\\s*toggleSummary\\(\\);\\s*\\}\\s*\\}\\);/", $code, 'the peek: the SAME function, only while collapsed');
        $this->assertSame(1, substr_count($code, 'toggleSummary'.'()') - substr_count($code, 'function toggleSummary()'), 'called by the peek only (the bar passes the function itself): no second code path');
    }

    public function test_the_script_hides_the_collapsed_panel_below_1024px_and_never_makes_the_panel_itself_inert(): void
    {
        $code = $this->script();

        $this->assertStringContainsString("var peek = summaryPanel.getAttribute('data-open') === 'false' && window.innerWidth < 1024;", $code);
        $this->assertStringContainsString("summaryPanel.setAttribute('aria-hidden', 'true');", $code);
        $this->assertStringContainsString("child.setAttribute('inert', '');", $code, 'what is in it');
        $this->assertStringNotContainsString("summaryPanel.setAttribute('inert'", $code, 'a browser does not deliver a tap to an inert element: the peek could not be tapped');
        $this->assertStringContainsString("window.addEventListener('resize', syncSummaryAccess);", $code);
        $this->assertStringContainsString('summaryPanel.hidden = ! (cart && Array.isArray(cart.lines) && cart.lines.length > 0);', $code, 'no lines, no peek');
    }

    // ---- fix 3: the bare hamburger ---------------------------------------------------------------------------------------------------

    public function test_the_menu_toggle_is_one_icon_and_a_visually_hidden_name_with_its_attributes_kept(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button class="sf-menu-toggle" type="button" aria-controls="sf-menu" aria-expanded="false" data-sf-menu-toggle>\s*<svg class="sf-menu-icon"[^>]*aria-hidden="true"[^>]*focusable="false">(<path d="[^"]+"\/>){3}<\/svg>\s*<span class="sf-sr-only">Categories<\/span>\s*<\/button>/', $html);
        preg_match('/<button class="sf-menu-toggle".*?<\/button>/s', $html, $button);
        $this->assertSame('Categories', trim(strip_tags($button[0])), 'the only text is the hidden accessible name');
        $this->assertStringNotContainsString('aria-label', $button[0], 'no label duplicating the name');
        $this->assertSame(2, substr_count($html, '<svg'), 'the hamburger and the bag, nothing else');
        $this->assertNoActiveContent($html, 'layout');
    }

    public function test_the_hamburger_has_no_border_background_or_radius_and_a_44px_target(): void
    {
        $css = $this->css();

        $this->assertStringNotContainsString('\\2261', $css, 'the old bordered "≡ Categories" glyph is gone');
        $bare = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/[^{}]*\.sf-menu-toggle[^{}]*\{[^{}]*\}/', $bare, $rules);

        foreach ($rules[0] as $rule) {
            $this->assertDoesNotMatchRegularExpression('/border/', (string) preg_replace('/border(-radius)?:\s*0;/', '', $rule), 'no border or @apply border at rest: '.trim($rule));
        }

        $this->assertMatchesRegularExpression('/html\.js \.sf-menu-toggle \{[^}]*width: 2\.75rem;[^}]*height: 2\.75rem;[^}]*border: 0;[^}]*border-radius: 0;[^}]*background: transparent;/s', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 63\.999rem\) \{\s*\/\*[^*]*\*\/\s*html\.js \.sf-menu-toggle \{/', $css, 'phone widths only: from 1024px the toggle stays hidden');
        $this->assertMatchesRegularExpression("/\\.sf-menu-toggle\\[aria-expanded='true'\\] \\.sf-menu-icon path:nth-child\\(1\\) \\{\\s*transform: translateY\\(5px\\) rotate\\(45deg\\);/", $css, 'the X');
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.sf-menu-icon path \{\s*transition: none;/', $css);
    }

    public function test_the_safe_html_check_allows_exactly_the_two_known_icons(): void
    {
        $bag = '<svg class="sf-cart-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 8h12"/></svg>';
        $menu = '<svg class="sf-menu-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"/><line x1="4" y1="12" x2="20" y2="12"/></svg>';

        $this->assertNoActiveContent('<body>'.$bag.$menu.'</body>', 'the two known icons');

        foreach ([
            'an unknown svg' => '<svg class="sf-other-icon" aria-hidden="true"><path d="M0 0"/></svg>',
            'no class' => '<svg aria-hidden="true"><path d="M0 0"/></svg>',
            'not hidden from assistive technology' => '<svg class="sf-menu-icon"><path d="M0 0"/></svg>',
            'a script inside' => '<svg class="sf-menu-icon" aria-hidden="true"><script>alert(1)</script></svg>',
            'a handler on a path' => '<svg class="sf-menu-icon" aria-hidden="true"><path d="M0 0" onclick="x()"/></svg>',
            'a foreign child' => '<svg class="sf-cart-icon" aria-hidden="true"><use href="#x"/></svg>',
            'a style attribute' => '<svg class="sf-cart-icon" aria-hidden="true" style="width:9px"><path d="M0 0"/></svg>',
            'an href on the svg' => '<svg class="sf-cart-icon" aria-hidden="true" href="javascript:x()"><path d="M0 0"/></svg>',
        ] as $why => $html) {
            try {
                $this->assertNoActiveContent('<body>'.$html.'</body>', $why);
            } catch (AssertionFailedError) {
                continue;
            }

            $this->fail("The safe-html check let through: {$why}");
        }
    }
}
