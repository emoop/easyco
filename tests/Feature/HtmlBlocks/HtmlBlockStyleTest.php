<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Storefront\Appearance\AppearanceCompiler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HB-1: «Стил на блока»: the validator (every edge of every range, every colour form, the closed sets), the ONE closed CSS template (each field gives exactly its declaration) and the regex that
 * checks the WHOLE rule again in the token compiler. No database: the pure parts of HtmlBlockStyle.
 */
class HtmlBlockStyleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    // ---- the validator -----------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: ?string}> [the style, the key that is refused (null = valid)] */
    public static function styles(): array
    {
        $out = [];

        foreach (['pad_v' => 64, 'pad_h' => 64, 'border_w' => 4, 'radius' => 32] as $key => $max) {
            $out["{$key}: 0"] = [[$key => 0], null];
            $out["{$key}: the maximum"] = [[$key => $max], null];
            $out["{$key}: a numeric string"] = [[$key => (string) $max], null];
            $out["{$key}: an empty text is the default"] = [[$key => ''], null];
            $out["{$key}: max + 1"] = [[$key => $max + 1], $key];
            $out["{$key}: -1"] = [[$key => -1], $key];
            $out["{$key}: not a number"] = [[$key => 'abc'], $key];
            $out["{$key}: a unit"] = [[$key => '4px'], $key];
            $out["{$key}: CSS smuggled behind a number"] = [[$key => '1;color:red'], $key];
            $out["{$key}: an exponent"] = [[$key => '1e3'], $key];
            $out["{$key}: a decimal"] = [[$key => '1.5'], $key];
            $out["{$key}: 600 digits"] = [[$key => str_repeat('9', 600)], $key];
            $out["{$key}: an array"] = [[$key => [1]], $key];
        }

        foreach (['bg', 'color', 'border_color'] as $key) {
            $out["{$key}: #2E7D32 is accepted"] = [[$key => '#2E7D32'], null];
            $out["{$key}: none"] = [[$key => null], null];
            $out["{$key}: an empty text is none"] = [[$key => ''], null];
            $out["{$key}: #fff is refused"] = [[$key => '#fff'], $key];
            $out["{$key}: #GGGGGG is refused"] = [[$key => '#GGGGGG'], $key];
            $out["{$key}: a name is refused"] = [[$key => 'red'], $key];
            $out["{$key}: a function is refused"] = [[$key => 'rgb(1,2,3)'], $key];
            $out["{$key}: CSS smuggled behind a colour"] = [[$key => '#2e7d32;color:red'], $key];
            $out["{$key}: a number is refused"] = [[$key => 123456], $key];
        }

        $out['width fixed with 100'] = [['width_mode' => 'fixed', 'width_px' => 100], null];
        $out['width fixed with 800'] = [['width_mode' => 'fixed', 'width_px' => '800'], null];
        $out['width fixed with 99'] = [['width_mode' => 'fixed', 'width_px' => 99], 'width_px'];
        $out['width fixed with 801'] = [['width_mode' => 'fixed', 'width_px' => 801], 'width_px'];
        $out['width fixed without a width'] = [['width_mode' => 'fixed'], 'width_px'];
        $out['width fixed with an empty width'] = [['width_mode' => 'fixed', 'width_px' => ''], 'width_px'];
        $out['width fixed with a unit'] = [['width_mode' => 'fixed', 'width_px' => '300px'], 'width_px'];
        $out['a width that is not fixed is checked too'] = [['width_mode' => 'full', 'width_px' => 'x'], 'width_px'];
        $out['width: full'] = [['width_mode' => 'full'], null];
        $out['width: a mode that does not exist'] = [['width_mode' => 'wide'], 'width_mode'];
        $out['align: a value that does not exist'] = [['align' => 'justify'], 'align'];
        $out['align: centre'] = [['align' => 'center'], null];
        $out['size: a value that does not exist'] = [['size' => 'huge'], 'size'];
        $out['size: large'] = [['size' => 'large'], null];
        $out['an unknown key'] = [['bg' => '#2e7d32', 'font_family' => 'x'], 'style'];

        return $out;
    }

    /** @param  array<string, mixed>  $style */
    #[DataProvider('styles')]
    public function test_the_validator_refuses_what_is_out_of_range_or_out_of_the_set(array $style, ?string $refused): void
    {
        [, $errors] = HtmlBlockStyle::validate($style);

        $refused === null ? $this->assertSame([], $errors, json_encode($style)) : $this->assertSame([$refused], array_keys($errors), json_encode($style));
    }

    public function test_a_style_that_is_not_an_array_is_refused_and_nothing_is_a_default(): void
    {
        $this->assertSame(['style'], array_keys(HtmlBlockStyle::validate('red')[1]));
        $this->assertSame([HtmlBlockStyle::DEFAULTS, []], HtmlBlockStyle::validate(null));
        $this->assertSame([HtmlBlockStyle::DEFAULTS, []], HtmlBlockStyle::validate([]));
    }

    public function test_the_validator_normalises_colours_numbers_and_the_stored_form(): void
    {
        [$clean, $errors] = HtmlBlockStyle::validate(['bg' => '#2E7D32', 'color' => '#FFFFFF', 'pad_v' => '6', 'pad_h' => '10', 'radius' => '4', 'align' => 'center']);

        $this->assertSame([], $errors);
        $this->assertSame('#2e7d32', $clean['bg']);
        $this->assertSame('#ffffff', $clean['color']);
        $this->assertSame([6, 10, 4], [$clean['pad_v'], $clean['pad_h'], $clean['radius']]);
        $this->assertSame(['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => 6, 'pad_h' => 10, 'radius' => 4, 'align' => 'center'], HtmlBlockStyle::storable($clean), 'only what differs from the defaults is stored');
    }

    public function test_a_style_of_defaults_is_not_set_and_is_stored_as_null(): void
    {
        foreach ([null, [], ['bg' => null, 'pad_v' => 0, 'pad_h' => '', 'width_mode' => 'auto', 'align' => 'left', 'size' => 'normal'], HtmlBlockStyle::DEFAULTS] as $style) {
            $this->assertNull(HtmlBlockStyle::storable($style), json_encode($style));
            $this->assertFalse(HtmlBlockStyle::isSet(HtmlBlockStyle::validate($style)[0]));
            $this->assertNull(HtmlBlockStyle::active($style));
        }

        $this->assertNotNull(HtmlBlockStyle::storable(['pad_v' => 1]));
        $this->assertNotNull(HtmlBlockStyle::active(['pad_v' => 1]));
        $this->assertNull(HtmlBlockStyle::active('{not json'), 'a hand-edited row never prints a wrapper');
        $this->assertNull(HtmlBlockStyle::active(['bg' => 'red']));
    }

    public function test_storing_a_style_that_does_not_validate_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HtmlBlockStyle::storable(['bg' => 'red']);
    }

    public function test_the_messages_are_in_both_languages(): void
    {
        $this->assertSame('A colour like #1a2b3c.', HtmlBlockStyle::validate(['bg' => 'red'])[1]['bg']);
        app()->setLocale('bg');
        $this->assertSame('Цвят във вида #1a2b3c.', HtmlBlockStyle::validate(['bg' => 'red'])[1]['bg']);
        $this->assertSame('Цяло число от 0 до 64.', HtmlBlockStyle::validate(['pad_v' => 65])[1]['pad_v']);
    }

    // ---- the CSS template -------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> [the style, the exact rule for block 5] */
    public static function rules(): array
    {
        $tail = 'width:fit-content;max-width:100%';

        return [
            'background' => [['bg' => '#2E7D32'], ".sf-hb-5{background:#2e7d32;{$tail}}"],
            'text colour' => [['color' => '#FFFFFF'], ".sf-hb-5{color:#ffffff;{$tail}}"],
            'padding both ways' => [['pad_v' => 6, 'pad_h' => 10], ".sf-hb-5{padding:6px 10px;{$tail}}"],
            'padding one way' => [['pad_v' => 6], ".sf-hb-5{padding:6px 0px;{$tail}}"],
            'padding the other way' => [['pad_h' => '12'], ".sf-hb-5{padding:0px 12px;{$tail}}"],
            'border in the text colour' => [['border_w' => 2], ".sf-hb-5{border:2px solid currentcolor;{$tail}}"],
            'border with a colour' => [['border_w' => 1, 'border_color' => '#112233'], ".sf-hb-5{border:1px solid #112233;{$tail}}"],
            'a border colour without a width is nothing to print' => [['border_color' => '#112233'], ''],
            'radius' => [['radius' => 4], ".sf-hb-5{border-radius:4px;{$tail}}"],
            'width: full' => [['width_mode' => 'full'], '.sf-hb-5{width:100%}'],
            'width: fixed' => [['width_mode' => 'fixed', 'width_px' => 300], '.sf-hb-5{width:300px;max-width:100%}'],
            'a width in px that is not fixed is dropped' => [['width_mode' => 'auto', 'width_px' => ''], ''],
            // HB-3.2: a fit-content box gets the margins that move it to the middle (centre) or to the right (right)
            'align: centre' => [['align' => 'center'], ".sf-hb-5{{$tail};margin-left:auto;margin-right:auto;text-align:center}"],
            'align: right' => [['align' => 'right'], ".sf-hb-5{{$tail};margin-left:auto;text-align:right}"],
            'align: left is the default' => [['align' => 'left'], ''],
            'size: small' => [['size' => 'small'], ".sf-hb-5{{$tail};font-size:0.875rem}"],
            'size: large' => [['size' => 'large'], ".sf-hb-5{{$tail};font-size:1.25rem}"],
            'size: normal is the default' => [['size' => 'normal'], ''],
            'all together' => [
                ['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => 6, 'pad_h' => 10, 'border_w' => 2, 'border_color' => '#112233', 'radius' => 4, 'width_mode' => 'fixed', 'width_px' => 280, 'align' => 'center', 'size' => 'large'],
                '.sf-hb-5{background:#2e7d32;color:#ffffff;padding:6px 10px;border:2px solid #112233;border-radius:4px;width:280px;max-width:100%;text-align:center;font-size:1.25rem}',
            ],
            'the example of the brief' => [
                ['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => 6, 'pad_h' => 10, 'radius' => 4, 'width_mode' => 'auto', 'align' => 'center'],
                '.sf-hb-5{background:#2e7d32;color:#ffffff;padding:6px 10px;border-radius:4px;width:fit-content;max-width:100%;margin-left:auto;margin-right:auto;text-align:center}',
            ],
            'nothing set' => [[], ''],
        ];
    }

    /** @param  array<string, mixed>  $style */
    #[DataProvider('rules')]
    public function test_each_field_gives_exactly_its_declaration_and_every_generated_rule_passes_the_check(array $style, string $rule): void
    {
        $this->assertSame($rule, HtmlBlockStyle::css(5, $style));

        $rule === '' || $this->assertTrue(HtmlBlockStyle::isValidRule($rule), $rule);
    }

    public function test_the_rule_never_depends_on_the_locale_and_never_prints_a_comma(): void
    {
        foreach (['bg', 'en', 'de'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('.sf-hb-12{padding:6px 10px;width:fit-content;max-width:100%}', HtmlBlockStyle::css(12, ['pad_v' => 6, 'pad_h' => 10]));
        }
    }

    public function test_a_style_that_does_not_validate_is_never_turned_into_a_rule(): void
    {
        foreach ([['bg' => '#2e7d32;}body{display:none'], ['pad_v' => '1;color:red'], ['bg' => 'url(x)'], ['width_mode' => 'fixed'], ['font_family' => 'x']] as $style) {
            try {
                HtmlBlockStyle::css(5, $style);
                $this->fail('a rule was printed for '.json_encode($style));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * HB-3.2: a fit-content box moves to the middle (centre: both margins) or to the right (right: the left margin); a full-width or fixed-width box and a left-aligned one get nothing new.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function alignmentMatrix(): array
    {
        return [
            'fit-content, centre' => [['align' => 'center'], '.sf-hb-5{width:fit-content;max-width:100%;margin-left:auto;margin-right:auto;text-align:center}'],
            'fit-content, right' => [['align' => 'right'], '.sf-hb-5{width:fit-content;max-width:100%;margin-left:auto;text-align:right}'],
            'fit-content, left' => [['align' => 'left', 'bg' => '#2e7d32'], '.sf-hb-5{background:#2e7d32;width:fit-content;max-width:100%}'],
            'fit-content, no align' => [['bg' => '#2e7d32'], '.sf-hb-5{background:#2e7d32;width:fit-content;max-width:100%}'],
            'full width, centre' => [['width_mode' => 'full', 'align' => 'center'], '.sf-hb-5{width:100%;text-align:center}'],
            'full width, right' => [['width_mode' => 'full', 'align' => 'right'], '.sf-hb-5{width:100%;text-align:right}'],
            'fixed width, centre' => [['width_mode' => 'fixed', 'width_px' => 300, 'align' => 'center'], '.sf-hb-5{width:300px;max-width:100%;text-align:center}'],
            'nothing set' => [['align' => 'left'], ''],
        ];
    }

    #[DataProvider('alignmentMatrix')]
    public function test_only_a_fit_content_box_gets_the_margins_that_move_it(array $style, string $rule): void
    {
        $this->assertSame($rule, HtmlBlockStyle::css(5, $style));

        if ($rule !== '') {
            $this->assertTrue(HtmlBlockStyle::isValidRule($rule), 'every generated rule passes the closed-list check');
        }
    }

    public function test_the_margin_declarations_are_accepted_by_the_closed_list(): void
    {
        $this->assertTrue(HtmlBlockStyle::isValidRule('.sf-hb-5{width:fit-content;max-width:100%;margin-left:auto;margin-right:auto;text-align:center}'));
        $this->assertTrue(HtmlBlockStyle::isValidRule('.sf-hb-5{width:fit-content;max-width:100%;margin-left:auto;text-align:right}'));
    }

    /** @return array<string, array{0: string}> */
    public static function badRules(): array
    {
        $ok = '.sf-hb-5{background:#2e7d32;width:fit-content;max-width:100%}';

        return [
            'an injected declaration' => [str_replace('}', ';color:red}', $ok)],
            'a closing brace and a new rule' => ['.sf-hb-5{background:#2e7d32;width:fit-content}body{display:none}'],
            'a second selector' => [$ok.'.sf-hb-6{color:#ffffff;width:100%}'],
            'a selector list' => ['.sf-hb-5,body{background:#2e7d32;width:100%}'],
            'url(' => ['.sf-hb-5{background:url(x);width:100%}'],
            'expression(' => ['.sf-hb-5{width:expression(alert(1))}'],
            'an import' => ['@import "x";'],
            'an upper case colour' => ['.sf-hb-5{background:#2E7D32;width:100%}'],
            'a short colour' => ['.sf-hb-5{background:#fff;width:100%}'],
            'NaN' => ['.sf-hb-5{padding:NaNpx 0px;width:100%}'],
            'a padding out of range' => ['.sf-hb-5{padding:65px 0px;width:100%}'],
            'a radius of 33' => ['.sf-hb-5{border-radius:33px;width:100%}'],
            'a border of 5' => ['.sf-hb-5{border:5px solid currentcolor;width:100%}'],
            'a width of 99' => ['.sf-hb-5{width:99px;width:100%}'],
            'a width of 801' => ['.sf-hb-5{width:801px}'],
            'a property that is not in the table' => ['.sf-hb-5{position:fixed;width:100%}'],
            'a text-align that is not in the set' => ['.sf-hb-5{text-align:justify;width:100%}'],
            'a font size that is not in the set' => ['.sf-hb-5{font-size:5rem;width:100%}'],
            // HB-3.2: only the two margins of a fit-content box are allowed, nothing else of the margin family
            'a margin shorthand' => ['.sf-hb-5{margin:9999px;width:100%}'],
            'a left margin of 1px' => ['.sf-hb-5{margin-left:1px;width:100%}'],
            'a top margin of auto' => ['.sf-hb-5{margin-top:auto;width:100%}'],
            'an auto margin next to a pixel margin' => ['.sf-hb-5{margin-left:auto;margin-right:1px;width:100%}'],
            'no declaration' => ['.sf-hb-5{}'],
            'no id' => ['.sf-hb-{width:100%}'],
            'id 0' => ['.sf-hb-0{width:100%}'],
            'an id with a letter' => ['.sf-hb-5a{width:100%}'],
            'no leading dot' => ['sf-hb-5{width:100%}'],
            'a trailing newline' => [$ok."\n"],
            'a leading space' => [' '.$ok],
            'a trailing comment' => [$ok.'/* x */'],
            'a space before the brace' => ['.sf-hb-5 {width:100%}'],
            'an empty string' => [''],
        ];
    }

    #[DataProvider('badRules')]
    public function test_the_check_refuses_every_mutated_rule(string $rule): void
    {
        $this->assertFalse(HtmlBlockStyle::isValidRule($rule), $rule);
    }

    public function test_the_token_compiler_checks_every_rule_again(): void
    {
        $compiler = new AppearanceCompiler;

        $css = $compiler->css([], [], [], [], [], [], [], ['.sf-hb-5{background:#2e7d32;width:fit-content;max-width:100%}']);
        $this->assertSame(".sf-hb-5{background:#2e7d32;width:fit-content;max-width:100%}\n", $css);

        foreach (['.sf-hb-5{background:red;width:100%}', '.sf-hb-5{width:100%}body{x:y}', 'body{width:100%}'] as $bad) {
            try {
                $compiler->css([], [], [], [], [], [], [], [$bad]);
                $this->fail('the compiler wrote '.$bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_with_no_rule_the_stylesheet_is_what_it_was(): void
    {
        $compiler = new AppearanceCompiler;

        $this->assertNull($compiler->css([], [], [], [], [], [], [], []), 'no override and no styled block: no stylesheet at all');
        $without = $compiler->css(['appearance.color.primary' => '#123456']);
        $this->assertNotNull($without);
        $this->assertStringNotContainsString('sf-hb', $without);
        $this->assertSame($without, $compiler->css(['appearance.color.primary' => '#123456'], [], [], [], [], [], [], []));
        $this->assertStringEndsNotWith("\n\n", $without, 'no empty block of rules is written');
    }
}
