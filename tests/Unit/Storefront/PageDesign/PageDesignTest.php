<?php

namespace Tests\Unit\Storefront\PageDesign;

use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Design\FieldKinds;
use App\Storefront\PageDesign\PageDesign;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * GL-2: the two plain sections of the Design screen — their fields, the validation (a number in a range, a member of a closed set, a switch), the px -> rem conversion, the variables that are
 * written (only what differs from the default) and the closed list the token compiler accepts.
 */
class PageDesignTest extends TestCase
{
    /** @return array{0: mixed, 1: list<string>} */
    private function number(mixed $value, array $spec = ['min' => 0, 'max' => 8, 'default' => 4]): array
    {
        $codes = [];
        $result = FieldKinds::number($value, $spec, 'p', function (string $path, string $code) use (&$codes): void {
            $codes[] = $code;
        });

        return [$result, $codes];
    }

    // ---- the `number` kind ----------------------------------------------------------------------------------------------------------------

    public function test_a_number_is_a_dot_decimal_in_its_range_and_a_whole_one_is_an_int(): void
    {
        $this->assertSame([4, []], $this->number('4'));
        $this->assertSame([4.5, []], $this->number('4.5'));
        $this->assertSame([4.25, []], $this->number(4.25));
        $this->assertSame([0, []], $this->number('0'));
        $this->assertSame([8, []], $this->number(8.0));
        $this->assertSame([4.13, []], $this->number(4.125), 'more decimals than allowed are rounded, never printed');
    }

    /** @return array<string, array{0: mixed}> */
    public static function notNumbers(): array
    {
        return [
            'a comma' => ['4,5'], 'a declaration' => ['1;color:red'], 'a brace' => ['1rem}body{x'], 'a unit' => ['4px'], 'an exponent' => ['1e1'], 'a sign' => ['-1'], 'a plus' => ['+1'],
            'a space' => ['4 '], 'empty' => [''], 'null' => [null], 'an array' => [['4']], 'true' => [true], 'above the range' => ['9'], 'a very long string' => [str_repeat('9', 500)],
            'three decimals' => ['4.123'], 'infinity' => [INF], 'nan' => [NAN],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notNumbers')]
    public function test_anything_that_is_not_a_plain_number_in_range_is_refused_with_the_default(mixed $value): void
    {
        [$result, $codes] = $this->number($value);

        $this->assertSame(['number_range'], $codes);
        $this->assertSame(4, $result);
    }

    // ---- px -> rem ------------------------------------------------------------------------------------------------------------------------

    public function test_pixels_are_written_as_rem_with_a_dot_and_zero_is_plain(): void
    {
        $this->assertSame('0', PageDesign::rem(0));
        $this->assertSame('0', PageDesign::rem(0.0));
        $this->assertSame('1rem', PageDesign::rem(16));
        $this->assertSame('0.5rem', PageDesign::rem(8));
        $this->assertSame('0.0625rem', PageDesign::rem(1), 'a whole pixel is exact');
        $this->assertSame('0.1875rem', PageDesign::rem(3));
        $this->assertSame('12.5rem', PageDesign::rem(200));
        $this->assertSame('20rem', PageDesign::rem(320));
        $this->assertSame('1.0313rem', PageDesign::rem(16.5));
    }

    public function test_the_number_formatting_does_not_depend_on_the_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, 0);
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'bg_BG.UTF-8', 'bg_BG', 'fr_FR');

        try {
            $this->assertSame('0.5rem', PageDesign::rem(8));
            $this->assertSame('clamp(1rem, 5.5vw, 10rem)', PageDesign::vars(PageDesign::PAGE, ['pad_vw' => 5.5] + PageDesign::defaults(PageDesign::PAGE))['--sf-page-pad']);
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }

    // ---- the defaults write nothing -------------------------------------------------------------------------------------------------------

    public function test_every_section_at_its_defaults_writes_no_variable_and_validates(): void
    {
        foreach (PageDesign::SECTIONS as $section) {
            [$clean, $errors] = PageDesign::validate($section, PageDesign::defaults($section));

            $this->assertSame([], $errors, $section);
            $this->assertSame(PageDesign::defaults($section), $clean, $section);
            $this->assertSame([], PageDesign::vars($section, $clean), $section);
            $this->assertTrue(PageDesign::isDefault($section, $clean), $section);
        }
    }

    // ---- the table: one changed value, one variable ---------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, string>}> */
    public static function table(): array
    {
        return [
            'pad min' => [PageDesign::PAGE, ['pad_min' => 24], ['--sf-page-pad' => 'clamp(1.5rem, 4vw, 10rem)']],
            'pad vw' => [PageDesign::PAGE, ['pad_vw' => 5.5], ['--sf-page-pad' => 'clamp(1rem, 5.5vw, 10rem)']],
            'pad vw zero' => [PageDesign::PAGE, ['pad_vw' => 0], ['--sf-page-pad' => 'clamp(1rem, 0vw, 10rem)']],
            'pad max' => [PageDesign::PAGE, ['pad_max' => 200], ['--sf-page-pad' => 'clamp(1rem, 4vw, 12.5rem)']],
            'card border' => [PageDesign::PAGE, ['card_border' => 2], ['--sf-card-border-width' => '0.125rem']],
            'card border off' => [PageDesign::PAGE, ['card_border' => 0], ['--sf-card-border-width' => '0']],
            'card radius' => [PageDesign::PAGE, ['card_radius' => 12], ['--sf-card-radius' => '0.75rem']],
            'card border phone' => [PageDesign::PAGE, ['card_border_m' => 1], ['--sf-card-border-width-mobile' => '0.0625rem']],
            'card radius phone' => [PageDesign::PAGE, ['card_radius_m' => 6], ['--sf-card-radius-mobile' => '0.375rem']],
            'gallery gap' => [PageDesign::PRODUCT, ['gal_gap' => 12], ['--sf-gal-gap' => '0.75rem']],
            'gallery radius' => [PageDesign::PRODUCT, ['gal_radius' => 8], ['--sf-gal-radius' => '0.5rem']],
            'column gap' => [PageDesign::PRODUCT, ['col_gap' => 48], ['--sf-product-col-gap' => '3rem']],
            'sticky off' => [PageDesign::PRODUCT, ['sticky' => false], ['--sf-summary-position' => 'static', '--sf-summary-max-height' => 'none']],
            'a gallery choice is not a variable' => [PageDesign::PRODUCT, ['gallery_desktop_mode' => 'thumbs-left', 'gallery_width' => '70'], []],
        ];
    }

    /** @param  array<string, mixed>  $change  @param  array<string, string>  $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('table')]
    public function test_one_changed_value_writes_exactly_its_variable(string $section, array $change, array $expected): void
    {
        [$clean, $errors] = PageDesign::validate($section, $change + PageDesign::defaults($section));

        $this->assertSame([], $errors);
        $this->assertSame($expected, PageDesign::vars($section, $clean));

        foreach ($expected as $variable => $value) {
            $this->assertTrue(PageDesign::isValidVar($variable, $value), "{$variable}: {$value}");
        }
    }

    // ---- refusals -------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: mixed}> */
    public static function refusals(): array
    {
        $bad = ['1;color:red', '1rem}body{x', str_repeat('9', 500), '', '-1', '4,5', '1e3', 'javascript:alert(1)'];
        $cases = [];

        foreach (['pad_min' => PageDesign::PAGE, 'pad_vw' => PageDesign::PAGE, 'pad_max' => PageDesign::PAGE, 'card_border' => PageDesign::PAGE, 'card_radius' => PageDesign::PAGE, 'card_border_m' => PageDesign::PAGE, 'card_radius_m' => PageDesign::PAGE,
            'gal_gap' => PageDesign::PRODUCT, 'gal_radius' => PageDesign::PRODUCT, 'col_gap' => PageDesign::PRODUCT] as $field => $section) {
            foreach ($bad as $i => $value) {
                $cases["{$field} #{$i}"] = [$section, $field, $value];
            }
        }

        foreach (['pad_min' => 7, 'pad_min high' => 49, 'pad_vw' => 9, 'pad_max' => 15, 'pad_max high' => 321, 'card_border' => 5, 'card_radius' => 33, 'card_border_m' => 5, 'card_radius_m' => 33] as $label => $value) {
            $cases["out of range {$label}"] = [PageDesign::PAGE, explode(' ', $label)[0], $value];
        }

        foreach (['gal_gap' => 33, 'gal_radius' => 17, 'col_gap' => 97] as $field => $value) {
            $cases["out of range {$field}"] = [PageDesign::PRODUCT, $field, $value];
        }

        foreach (['gallery_desktop_mode' => 'masonry', 'gallery_mobile_mode' => 'grid', 'gallery_width' => '66', 'gallery_odd_last' => 'half', 'gallery_hover_zoom' => 'huge'] as $field => $value) {
            $cases["outside the set {$field}"] = [PageDesign::PRODUCT, $field, $value];
            $cases["injected {$field}"] = [PageDesign::PRODUCT, $field, '"><b>x</b>'];
            $cases["a number for {$field}"] = [PageDesign::PRODUCT, $field, 65];
        }

        $cases['sticky is not a switch'] = [PageDesign::PRODUCT, 'sticky', 'yes'];

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_value_outside_its_range_or_set_is_refused_and_falls_back_to_the_default(string $section, string $field, mixed $value): void
    {
        [$clean, $errors] = PageDesign::validate($section, [$field => $value] + PageDesign::defaults($section));

        $this->assertArrayHasKey($field, $errors);
        $this->assertSame(PageDesign::defaults($section)[$field], $clean[$field], 'what is kept is the default, never the refused value');
        $this->assertSame([], PageDesign::vars($section, $clean), 'nothing of it reaches the stylesheet');
    }

    public function test_the_minimum_padding_cannot_be_above_the_maximum(): void
    {
        [, $errors] = PageDesign::validate(PageDesign::PAGE, ['pad_min' => 48, 'pad_max' => 16] + PageDesign::defaults(PageDesign::PAGE));

        $this->assertSame(['pad_max'], array_keys($errors));
        $this->assertSame([], PageDesign::validate(PageDesign::PAGE, ['pad_min' => 16, 'pad_max' => 16] + PageDesign::defaults(PageDesign::PAGE))[1], 'equal is fine');
    }

    public function test_an_unknown_key_and_a_non_array_are_refused(): void
    {
        $this->assertArrayHasKey('hack', PageDesign::validate(PageDesign::PAGE, ['hack' => '1'] + PageDesign::defaults(PageDesign::PAGE))[1]);
        $this->assertArrayHasKey('values', PageDesign::validate(PageDesign::PAGE, 'nope')[1]);
    }

    // ---- the closed list of the compiler --------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function badVars(): array
    {
        return [
            'an unknown variable' => ['--sf-evil', '1rem'],
            'a declaration in a length' => ['--sf-card-radius', '1rem;color:red'],
            'a brace' => ['--sf-gal-gap', '1rem}body{x'],
            'px instead of rem' => ['--sf-card-radius', '8px'],
            'a long length' => ['--sf-card-radius', '123rem'],
            'a clamp with a brace' => ['--sf-page-pad', 'clamp(1rem, 4vw, 10rem)}x{'],
            'a clamp with px' => ['--sf-page-pad', 'clamp(16px, 4vw, 10rem)'],
            'a position that is not static' => ['--sf-summary-position', 'fixed'],
            'a max height that is not none' => ['--sf-summary-max-height', '1px'],
            'not a string' => ['--sf-gal-gap', 8],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badVars')]
    public function test_the_compiler_refuses_a_variable_that_is_not_on_its_closed_list(string $variable, mixed $value): void
    {
        $this->assertFalse(PageDesign::isValidVar($variable, $value));

        $this->expectException(InvalidArgumentException::class);
        app(AppearanceCompiler::class)->css([], [], [], [], [], [], [$variable => $value]);
    }

    public function test_the_compiler_writes_a_valid_page_variable_after_the_others_and_nothing_without_one(): void
    {
        $compiler = app(AppearanceCompiler::class);

        $this->assertNull($compiler->css([]), 'an untouched shop compiles to nothing');
        $this->assertNull($compiler->css([], [], [], [], [], [], []));
        $this->assertSame(":root {\n    --sf-card-radius: 0.75rem;\n    --sf-gal-gap: 0;\n}\n", $compiler->css([], [], [], [], [], [], ['--sf-card-radius' => '0.75rem', '--sf-gal-gap' => '0']));
    }
}
