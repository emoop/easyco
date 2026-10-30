<?php

namespace Tests\Feature\Storefront;

use Tests\TestCase;

/**
 * HB-3.1: the paragraph rule of an HTML block is a gap BETWEEN two paragraphs only. The HB-3 rule gave every paragraph a bottom margin (`margin: 0 0 .5em`) and then removed it from the last one; a
 * bottom margin is space the block does not centre on. The check reads the stylesheet source: the rule is in three places (right column, mega menu, footer) and carries no bottom margin.
 */
class HtmlBlockParagraphSpacingTest extends TestCase
{
    private function block(): string
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));
        preg_match('#/\* HB-3\.1:.*?\n(.*?)\n    \}\n\n    \.sf-summary-html p \+ p.*?\}#s', $css, $m);

        return ($m[0] ?? '');
    }

    public function test_a_paragraph_has_no_margin_and_only_the_second_one_onwards_gets_a_gap(): void
    {
        $block = $this->block();

        $this->assertNotSame('', $block, 'the HB-3.1 rule is in the stylesheet');
        $this->assertMatchesRegularExpression('/\.sf-summary-html p,\s*\.sf-mega-html p,\s*\.sf-ft-html p \{\s*margin: 0;\s*\}/', $block);
        $this->assertMatchesRegularExpression('/\.sf-summary-html p \+ p,\s*\.sf-mega-html p \+ p,\s*\.sf-ft-html p \+ p \{\s*margin-top: 0\.5em;\s*\}/', $block);
    }

    public function test_no_paragraph_rule_of_a_block_has_a_bottom_margin(): void
    {
        $block = $this->block();

        $this->assertNotSame('', $block, 'the HB-3.1 rule is found (an empty text would pass every check below)');
        $this->assertStringNotContainsString('margin-bottom', $block, 'nothing below the text: a centred block is not moved');
        $this->assertStringNotContainsString('0 0 0.5em', $block);
        $this->assertStringNotContainsString(':last-child', $block, 'the gap is set by the sibling rule, not by the last child');
    }
}
