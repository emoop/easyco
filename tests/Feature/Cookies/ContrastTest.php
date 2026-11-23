<?php

namespace Tests\Feature\Cookies;

use App\Storefront\Cookies\Contrast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CC-1: the WCAG contrast function the button colour is checked with: known pairs. */
class ContrastTest extends TestCase
{
    public function test_black_on_white_is_21_to_1(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#000000', '#ffffff'), 0.001);
        $this->assertEqualsWithDelta(1.0, Contrast::ratio('#777777', '#777777'), 0.001);
        $this->assertEqualsWithDelta(Contrast::ratio('#000000', '#ffffff'), Contrast::ratio('#ffffff', '#000000'), 0.0001, 'symmetric');
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function known(): array
    {
        return [
            'the default #111111 on white' => ['#111111', 18.88],
            'a mid-grey #767676 on white (the AA limit)' => ['#767676', 4.54],
            'a light grey #aaaaaa on white' => ['#aaaaaa', 2.32],
            'a brand-like blue #1d4ed8 on white' => ['#1d4ed8', 6.70],
        ];
    }

    #[DataProvider('known')]
    public function test_known_pairs_against_the_white_dialog(string $color, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, Contrast::ratio($color, Contrast::DIALOG_BACKGROUND), 0.02);
    }

    /** the text inside a button is the better of white and black: a mid-grey fails BOTH at 4.5:1, a dark and a light colour do not */
    public function test_best_text_is_white_or_black_and_a_mid_grey_has_no_good_one(): void
    {
        $this->assertSame('#ffffff', Contrast::bestText('#111111')['color']);
        $this->assertSame('#ffffff', Contrast::bestText('#1d4ed8')['color']);
        $this->assertSame('#000000', Contrast::bestText('#f5d000')['color']);
        $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, Contrast::bestText('#1d4ed8')['ratio']);

        // a mid-grey FAILS as a text colour on white (it would be a poor button text), yet the computed best text on it still reads
        $this->assertLessThan(Contrast::TEXT_MIN, Contrast::ratio('#808080', Contrast::DIALOG_BACKGROUND));
        $this->assertSame('#000000', Contrast::bestText('#808080')['color']);
        $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, Contrast::bestText('#808080')['ratio']);
    }

    /** FINDING: white-or-black text on ANY colour reaches 4.5:1 (the two ratios cross at 4.58:1), so the «text below 4.5:1» refusal can never fire; the 3:1 rule against the dialog is the one that refuses */
    public function test_the_best_text_colour_always_reaches_4_5_to_1(): void
    {
        $worst = 99.0;

        for ($r = 0; $r <= 255; $r += 15) {
            for ($g = 0; $g <= 255; $g += 15) {
                for ($b = 0; $b <= 255; $b += 15) {
                    $worst = min($worst, Contrast::bestText(sprintf('#%02x%02x%02x', $r, $g, $b))['ratio']);
                }
            }
        }

        $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $worst);
        $this->assertLessThan(4.7, $worst, 'the crossing point is about 4.58:1');
    }

    public function test_only_lower_case_six_digit_hex_is_a_colour(): void
    {
        $this->assertTrue(Contrast::isHex('#1a2b3c'));
        foreach (['#ABCDEF', '#abc', '1a2b3c', '#1a2b3g', 'red', '', null, 123, '#1a2b3c ', "#1a2b3c\n"] as $bad) {
            $this->assertFalse(Contrast::isHex($bad), var_export($bad, true));
        }
    }
}
