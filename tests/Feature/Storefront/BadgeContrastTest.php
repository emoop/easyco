<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Badges\BadgeSet;
use PHPUnit\Framework\TestCase;

/**
 * P7-1.1: the six contrast pairs of the card badges. Every pair must pass WCAG AA (4.5:1) for the small text at 100 / 90 / 80 % of the BACKGROUND over white (a photo behind the badge is not computable: the hint of the
 * «Плътност на фона» field says «при по-светла снимка изберете 100%»); the CSS custom properties of `.sf-cbadge--{key}` are exactly BadgeSet::PAIRS (one source of truth); the three shapes are three different radii; and the
 * card badge never shares a class name with the menu's `.sf-badge` label (an unlayered rule that once made every badge a pill).
 */
class BadgeContrastTest extends TestCase
{
    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(static fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function contrast(array $a, array $b): float
    {
        [$x, $y] = [self::luminance($a), self::luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** the background at an opacity over WHITE (what the browser paints on a white card) */
    private static function over(array $rgb, int $percent): array
    {
        return array_map(static fn (float $c): float => $c * $percent / 100 + 255 * (1 - $percent / 100), $rgb);
    }

    private function css(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css'));
    }

    /** ★ every pair at every opacity passes AA for the small text */
    public function test_every_pair_passes_aa_at_every_opacity(): void
    {
        $this->assertSame(BadgeSet::COLOURS, array_keys(BadgeSet::PAIRS));

        foreach (BadgeSet::PAIRS as $key => $pair) {
            foreach (BadgeSet::OPACITIES as $opacity) {
                $ratio = self::contrast(self::over(self::rgb($pair['bg']), $opacity), self::rgb($pair['fg']));

                $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s at %d%%: %.2f:1', $key, $opacity, $ratio));
            }
        }
    }

    /** the guard really guards: the first version's values would fail it (the property of the test, not of the shop) */
    public function test_the_contrast_function_refuses_a_weak_pair(): void
    {
        $this->assertLessThan(4.5, self::contrast(self::over(self::rgb('#15803d'), 80), self::rgb('#ffffff')), 'a lighter green with white text at 80% is below AA');
        $this->assertLessThan(4.5, self::contrast(self::rgb('#ffffff'), self::rgb('#e5e5e5')), 'white text on light grey is below AA');
    }

    /** ★ the stylesheet carries exactly these pairs */
    public function test_the_css_pairs_are_the_php_pairs(): void
    {
        $css = $this->css();

        foreach (BadgeSet::PAIRS as $key => $pair) {
            $this->assertSame(1, preg_match('/\.sf-cbadge--'.$key.' \{ --sf-cbadge-rgb: (\d+) (\d+) (\d+); --sf-cbadge-fg: (#[0-9a-f]{6}); \}/', $css, $m), $key.' is in the CSS');
            $this->assertEquals(self::rgb($pair['bg']), [(int) $m[1], (int) $m[2], (int) $m[3]], $key.' background');
            $this->assertSame($pair['fg'], $m[4], $key.' text');
        }

        $this->assertStringContainsString('.sf-cbadge--o90 { --sf-cbadge-a: 0.9; }', $css);
        $this->assertStringContainsString('.sf-cbadge--o80 { --sf-cbadge-a: 0.8; }', $css);
        $this->assertStringContainsString('background: rgb(var(--sf-cbadge-rgb) / var(--sf-cbadge-a));', $css, 'only the BACKGROUND takes the opacity');
        $this->assertMatchesRegularExpression('/\.sf-cbadge \{[^}]*color: var\(--sf-cbadge-fg\);/s', $css, 'the text colour is its own, fully opaque');
    }

    /** ★ the three shapes are three radii (a pill, a small radius, none) */
    public function test_the_shapes_are_three_different_radii(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('.sf-cbadge--square { border-radius: 0; }', $css);
        $this->assertStringContainsString('.sf-cbadge--rounded { border-radius: 0.25rem; }', $css);
        $this->assertStringContainsString('.sf-cbadge--pill { border-radius: 9999px; }', $css);
        $this->assertDoesNotMatchRegularExpression('/\.sf-cbadge \{[^}]*border-radius/s', $css, 'the base rule sets no radius: the shape class decides');
    }

    /** ★ the cause of «always a pill»: the menu's unlayered `.sf-badge` rule. The card badge must not use that class name anywhere */
    public function test_the_card_badge_never_uses_the_menus_class_name(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\n\.sf-badge \{[^}]*border-radius: 9999px;/s', $css, 'the menu label stays a pill: it is the other component and is not touched');

        $start = strpos($css, '/* ---- card badges (P7-1');
        $block = substr($css, $start, strpos($css, '.sf-cbadge--pill', $start) - $start + 80);
        $this->assertSame(0, preg_match('/\.sf-badge(?![\w-])/', preg_replace('#/\*.*?\*/#s', '', $block)), 'the card badge block has no `.sf-badge` selector (its comment may name the other component)');

        $root = dirname(__DIR__, 3).'/resources/views/storefront/partials';

        foreach (['badges.blade.php', 'card.blade.php'] as $view) {
            $this->assertSame(0, preg_match('/\bsf-badge(?![\w-])/', preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($root.'/'.$view))), $view.' (outside its comments)');
        }
    }
}
