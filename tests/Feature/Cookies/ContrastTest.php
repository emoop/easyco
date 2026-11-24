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

    // ---- DM-1: the dark mode's button colour ----------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function goodDarkButtons(): array
    {
        return ['the default orange' => ['#f97316'], 'white' => ['#ffffff'], 'a light blue' => ['#3b82f6'], 'upper case' => ['#F97316']];
    }

    #[DataProvider('goodDarkButtons')]
    public function test_a_dark_button_colour_passes_both_rules_with_ratios_the_code_computes(string $color): void
    {
        $check = Contrast::darkButton($color);
        $lower = strtolower($color);

        $this->assertTrue($check['ok']);
        $this->assertNull($check['reason']);
        $this->assertSame(Contrast::ratio($lower, Contrast::DARK_BACKGROUND), $check['buttonRatio']);
        $this->assertSame(Contrast::bestText($lower)['ratio'], $check['textRatio']);
        $this->assertSame(Contrast::bestText($lower)['color'], $check['textColour']);
        $this->assertGreaterThanOrEqual(Contrast::COMPONENT_MIN, $check['buttonRatio']);
        $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $check['textRatio']);
    }

    public function test_the_text_on_orange_is_black_and_on_white_it_is_black_too(): void
    {
        $this->assertSame('#000000', Contrast::darkButton('#f97316')['textColour']);
        $this->assertSame('#000000', Contrast::darkButton('#ffffff')['textColour']);
    }

    public function test_a_dark_grey_button_is_refused_because_it_does_not_stand_out_from_the_black_page(): void
    {
        $check = Contrast::darkButton('#333333');

        $this->assertFalse($check['ok']);
        $this->assertSame('button', $check['reason']);
        $this->assertLessThan(Contrast::COMPONENT_MIN, $check['buttonRatio']);
        $this->assertSame(Contrast::ratio('#333333', Contrast::DARK_BACKGROUND), $check['buttonRatio']);
        $this->assertFalse(Contrast::darkButton('#000000')['ok']);
    }

    public function test_a_value_that_is_not_a_hex_colour_is_refused_as_a_format_error(): void
    {
        foreach (['orange', '#fff', '#gggggg', '', null, 12, '#f97316; x'] as $bad) {
            $check = Contrast::darkButton($bad);

            $this->assertFalse($check['ok'], json_encode($bad));
            $this->assertSame('format', $check['reason']);
            $this->assertNull($check['textColour']);
        }
    }

    public function test_the_new_constants_have_their_own_names_and_the_cookie_dialogs_are_untouched(): void
    {
        $this->assertSame(3.0, Contrast::COMPONENT_MIN);
        $this->assertSame('#000000', Contrast::DARK_BACKGROUND);
        $this->assertSame('#ffffff', Contrast::DIALOG_BACKGROUND);
        $this->assertSame(3.0, Contrast::AGAINST_DIALOG_MIN);
    }
}
