<?php

namespace Tests\Feature\Storefront;

use PHPUnit\Framework\TestCase;

/**
 * Checkout form v2, stage C1d-B, the three visual fixes (the owner's): static checks of the stylesheet and the script. How it LOOKS is
 * not testable without a browser; what is pinned is that the rules exist, are scoped where they must be, and the script feeds the variable.
 */
class StorefrontCheckoutVisualRulesTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css');
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront-checkout.js');
    }

    public function test_g1_the_focus_ring_of_the_compact_promo_box_surrounds_the_field_and_the_button_together(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.sf-promo-compact-field:focus-within \{[^}]*outline: 2px solid/', $css, 'the ring is on the wrapper');
        $this->assertMatchesRegularExpression('/\.sf-promo-compact-input:focus-visible \{\s*outline: none;/', $css, 'and the input has no ring of its own');
        $this->assertDoesNotMatchRegularExpression('/\.sf-cart-promo[a-z-]*:focus/', $css, 'the cart page\'s own box is not touched');
    }

    public function test_g2_buttons_labels_and_selects_get_the_pointer_and_a_disabled_button_keeps_not_allowed(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/button:not\(:disabled\),\s*\[role=\'button\'\]:not\(\[aria-disabled=\'true\'\]\),\s*label\[for\],\s*select \{\s*cursor: pointer;\s*\}/', $css);
        $this->assertMatchesRegularExpression('/\.sf-place-order:disabled \{[^}]*cursor-not-allowed/', $css, 'the component rule of a disabled button wins over the base layer');

        $base = substr($css, (int) strpos($css, '@layer base'), (int) strpos($css, '@layer components') - (int) strpos($css, '@layer base'));
        $this->assertStringContainsString('cursor: pointer;', $base, 'one base rule, in the base layer');
    }

    public function test_g3_the_summary_top_is_a_variable_with_the_old_value_as_its_fallback_and_the_script_sets_it(): void
    {
        $css = $this->css();
        $script = $this->script();

        $this->assertMatchesRegularExpression('/\.sf-checkout-summary \{\s*grid-area: summary;\s*position: sticky;[^}]*top: var\(--sf-summary-top, 5\.5rem\);/s', $css);
        $this->assertStringContainsString("summaryPanel.style.setProperty('--sf-summary-top', Math.round(top) + 'px');", $script);
        $this->assertStringContainsString('var top = Math.max(headerHeight + 8, naturalTop - 24);', $script, 'at most ~24px above its natural place, never under the header');
        $this->assertStringContainsString("summaryLines.style.setProperty('max-height'", $script, 'a tall summary scrolls its ITEM LIST only');
        $this->assertStringContainsString("summaryLines.style.setProperty('overflow-y', 'auto');", $script);
        $this->assertStringContainsString('if (window.innerWidth < 1024) {', $script, 'the mobile panel is not touched');
        $this->assertStringContainsString("window.addEventListener('resize', scheduleSummaryFit);", $script);
        $this->assertStringContainsString('fitTimer = window.setTimeout(fitSummary, 120);', $script, 'debounced');
    }
}
