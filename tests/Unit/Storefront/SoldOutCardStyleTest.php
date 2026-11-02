<?php

namespace Tests\Unit\Storefront;

use Tests\TestCase;

/**
 * DRIBNI-1 (item 4): «Изчерпан» on a product card is a quiet line — the muted text token, the footer's text size, no capitals, no background, no border and no radius. The rule is read from the stylesheet (the
 * card's markup is `sf-stock sf-stock-out` inside `.sf-card-footer`; the product page's own stock line is not changed).
 */
class SoldOutCardStyleTest extends TestCase
{
    private function rule(): string
    {
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));

        preg_match('/\.sf-card-footer > \.sf-stock-out \{([^}]*)\}/', $css, $m);

        $this->assertArrayHasKey(1, $m, 'the card\'s sold-out rule exists');

        return $m[1];
    }

    public function test_the_text_is_the_muted_token_at_the_footer_size_without_capitals(): void
    {
        $rule = $this->rule();

        $this->assertStringContainsString('color: var(--sf-pdp-muted);', $rule);
        $this->assertStringContainsString('font-size: 0.8125rem;', $rule);
        $this->assertStringContainsString('text-transform: none;', $rule);
        $this->assertStringContainsString('letter-spacing: 0;', $rule);
    }

    public function test_there_is_no_background_border_or_radius(): void
    {
        $rule = $this->rule();

        $this->assertStringContainsString('background: none;', $rule);
        $this->assertStringContainsString('border: 0;', $rule);
        $this->assertStringContainsString('border-radius: 0;', $rule);
        preg_match_all('/background\s*:\s*([^;]+);/', $rule, $values);
        $this->assertNotEmpty($values[1]);

        foreach ($values[1] as $value) {
            $this->assertSame('none', trim($value), 'no background colour or image');
        }
    }
}
