<?php

namespace Tests\Feature\Storefront;

use PHPUnit\Framework\TestCase;

/**
 * ACC-1: the product page's accordion (native `<details class="sf-psec">`) opens and closes with a smooth growing height. CSS only: the open and close are animated where `interpolate-size` and
 * `::details-content` exist, the chevron turns with the same timing, and `prefers-reduced-motion: reduce` switches every transition off. The markup is not touched here (ProductSectionsTest covers it).
 */
class ProductAccordionMotionTest extends TestCase
{
    private function css(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css'));
    }

    /** the one `@supports` block of the accordion animation (the rules between its opening line and the 4-space closing brace) */
    private function animationBlock(): string
    {
        $this->assertMatchesRegularExpression(
            '/@supports \(interpolate-size: allow-keywords\) and selector\(details::details-content\) \{/',
            $this->css(),
            'the animation is gated by @supports, so a browser without it keeps the instant open and close'
        );

        preg_match('/@supports \(interpolate-size: allow-keywords\) and selector\(details::details-content\) \{(.*?)\n    \}/s', $this->css(), $m);

        return $m[1] ?? '';
    }

    public function test_the_section_grows_and_shrinks_through_a_height_transition(): void
    {
        $block = $this->animationBlock();

        $this->assertMatchesRegularExpression('/\.sf-psec \{\s*interpolate-size: allow-keywords;/', $block);
        $this->assertMatchesRegularExpression('/\.sf-psec::details-content \{\s*block-size: 0;\s*overflow: hidden;\s*transition:\s*block-size 250ms ease-out,\s*content-visibility 250ms allow-discrete;/', $block);
        $this->assertMatchesRegularExpression('/\.sf-psec\[open\]::details-content \{\s*block-size: auto;/', $block);
    }

    public function test_the_chevron_turns_with_the_same_timing(): void
    {
        $this->assertMatchesRegularExpression('/\.sf-psec-title::after \{\s*transition: transform 250ms ease-out;/', $this->animationBlock());
    }

    public function test_reduced_motion_switches_the_transitions_off(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{\s*\.sf-psec::details-content,\s*\.sf-psec-title::after \{\s*transition: none;\s*\}\s*\}/',
            $this->css()
        );
    }

    public function test_the_rules_stay_in_the_components_layer_next_to_the_other_accordion_rules(): void
    {
        $css = $this->css();
        $accordion = strpos($css, '.sf-psec-list {');
        $animation = strpos($css, '.sf-psec::details-content');

        $this->assertNotFalse($accordion);
        $this->assertNotFalse($animation);
        // the nearest `@layer` opened before the accordion rules is the components layer (an unlayered rule would beat the layered ones)
        $this->assertSame('@layer components {', substr($css, strrpos(substr($css, 0, $accordion), '@layer '), strlen('@layer components {')));
        $this->assertGreaterThan($accordion, $animation, 'the animation sits after the accordion base rules');
    }
}
