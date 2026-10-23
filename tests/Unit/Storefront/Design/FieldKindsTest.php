<?php

namespace Tests\Unit\Storefront\Design;

use App\Storefront\Design\FieldKinds;
use Tests\TestCase;

/**
 * DS-1: every field kind of the shared core, valid and with each failure code. The failures are collected as `[path, code, params]`; the callers (the header and the footer validators) turn
 * the codes into their own messages.
 */
class FieldKindsTest extends TestCase
{
    /** @param  array<string, mixed>  $spec  @return array{0: mixed, 1: list<array{0: string, 1: string, 2: array<string, mixed>}>} */
    private function check(mixed $value, array $spec, string $path = 'p'): array
    {
        $fails = [];
        $result = FieldKinds::validate($value, $spec, $path, function (string $path, string $code, array $params = []) use (&$fails): void {
            $fails[] = [$path, $code, $params];
        });

        return [$result, $fails];
    }

    /** @return list<string> the codes that failed */
    private function codes(mixed $value, array $spec): array
    {
        return array_column($this->check($value, $spec)[1], 1);
    }

    // ---- the kinds both catalogs had ------------------------------------------------------------------------------------------------

    public function test_int(): void
    {
        $spec = ['kind' => 'int', 'min' => 16, 'max' => 120, 'default' => 28];

        $this->assertSame([40, []], $this->check(40, $spec));
        $this->assertSame([36, []], $this->check('36', $spec), 'a numeric string is accepted');
        $this->assertSame([28, [['p', 'int_range', ['min' => 16, 'max' => 120]]]], $this->check(999, $spec), 'out of range: the code and the params of the header, the default');
        $this->assertSame([28, [['p', 'int_range', ['min' => 16, 'max' => 120]]]], $this->check('abc', $spec));
        $this->assertSame([28, [['p', 'int_range', ['min' => 16, 'max' => 120]]]], $this->check(12.5, $spec));
        $this->assertSame([16, [['p', 'int_range', ['min' => 16, 'max' => 120]]]], $this->check(1, ['kind' => 'int', 'min' => 16, 'max' => 120]), 'no default: the minimum');
    }

    public function test_int_options_of_the_footer_and_of_a_strict_spec(): void
    {
        $footer = ['kind' => 'int', 'min' => 1, 'max' => 5, 'int_code' => 'bad_choice'];

        $this->assertSame([1, [['p', 'bad_choice', []]]], $this->check(9, $footer), 'the footer: bad_choice and no params');
        $this->assertSame([3, []], $this->check('3', $footer));
        $this->assertSame(['int_range'], $this->codes('3', ['kind' => 'int', 'min' => 1, 'max' => 5, 'numeric_string' => false]), 'a numeric string can be refused');
    }

    public function test_enum_and_bool(): void
    {
        $enum = ['kind' => 'enum', 'options' => ['a', 'b'], 'default' => 'b'];

        $this->assertSame(['a', []], $this->check('a', $enum));
        $this->assertSame(['b', [['p', 'bad_choice', []]]], $this->check('z', $enum));
        $this->assertSame(['a', [['p', 'bad_choice', []]]], $this->check(1, ['kind' => 'enum', 'options' => ['a', 'b']]), 'no default: the first option');
        $this->assertSame([true, []], $this->check(true, ['kind' => 'bool']));
        $this->assertSame([false, [['p', 'bad_choice', []]]], $this->check('yes', ['kind' => 'bool']));
    }

    public function test_text(): void
    {
        $spec = ['kind' => 'text', 'max' => 10, 'required' => true];

        $this->assertSame(['hello', []], $this->check('  hello  ', $spec), 'trimmed');
        $this->assertSame(['', [['p', 'required', []]]], $this->check('', $spec));
        $this->assertSame([str_repeat('a', 10), [['p', 'too_long', ['max' => 10]]]], $this->check(str_repeat('a', 11), $spec));
        $this->assertSame(['', [['p', 'not_plain', []]]], $this->check("a\nb", $spec));
        $this->assertSame(['', []], $this->check(null, ['kind' => 'text']), 'not required, nothing');
        $this->assertSame([str_repeat('я', 120), []], $this->check(str_repeat('я', 120), ['kind' => 'text']), 'the default max is 120 characters, counted as characters');
        $this->assertSame(['too_long'], $this->codes(str_repeat('a', 121), ['kind' => 'text']));
    }

    public function test_textarea_keeps_line_breaks_and_refuses_other_control_characters(): void
    {
        $spec = ['kind' => 'textarea', 'max' => 20];

        $this->assertSame(["one\ntwo", []], $this->check("one\r\ntwo", $spec));
        $this->assertSame(['', [['p', 'not_plain', []]]], $this->check("ok\x07bad", $spec));
        $this->assertSame(['too_long'], $this->codes(str_repeat('a', 21), $spec));
    }

    public function test_email(): void
    {
        $this->assertSame(['a@b.test', []], $this->check(' a@b.test ', ['kind' => 'email']));
        $this->assertSame(['', []], $this->check('', ['kind' => 'email']));
        $this->assertSame(['', [['p', 'bad_email', []]]], $this->check('not-an-email', ['kind' => 'email']));
    }

    public function test_url_accepts_a_shop_path_and_https_and_refuses_everything_else(): void
    {
        $spec = ['kind' => 'url'];

        $this->assertSame(['/sale', []], $this->check('/sale', $spec));
        $this->assertSame(['https://example.test/x', []], $this->check('https://example.test/x', $spec));
        $this->assertSame(['', []], $this->check('', $spec));
        $this->assertSame(['', [['p', 'required', []]]], $this->check('', ['kind' => 'url', 'required' => true]));

        foreach (['http://example.test/x', 'javascript:alert(1)', 'data:text/html;base64,AAAA', '//evil.test/x', 'ftp://x.test/'] as $bad) {
            $this->assertSame(['bad_url'], $this->codes($bad, $spec), $bad);
        }
    }

    public function test_html_is_cleaned_and_a_too_long_one_is_refused(): void
    {
        $spec = ['kind' => 'html', 'max' => 100];

        [$clean, $fails] = $this->check('<p onclick="x()">Hi <b>there</b></p><script>alert(1)</script>', $spec);
        $this->assertSame([], $fails);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('<b>there</b>', $clean);

        $this->assertSame(['', [['p', 'html_too_long', ['max' => 100]]]], $this->check('<p>'.str_repeat('a', 150).'</p>', $spec));
        $this->assertSame(['', [['p', 'html_too_long', ['max' => 100]]]], $this->check(str_repeat('a', 900), $spec), 'an input over 8 times the limit is not even cleaned');
    }

    public function test_menu_and_html_block_are_ids(): void
    {
        $this->assertSame([5, []], $this->check(5, ['kind' => 'menu']));
        $this->assertSame([5, []], $this->check('5', ['kind' => 'menu']));
        $this->assertSame([null, []], $this->check('', ['kind' => 'menu']), 'a menu may be empty: the zone\'s own menu');
        $this->assertSame([null, [['p', 'bad_choice', []]]], $this->check('abc', ['kind' => 'menu']));
        $this->assertSame([null, [['p', 'bad_choice', []]]], $this->check(0, ['kind' => 'menu']));
        $this->assertSame([null, [['p', 'required', []]]], $this->check(null, ['kind' => 'html_block']), 'an html block is always required');
        $this->assertSame([7, []], $this->check(7, ['kind' => 'html_block']));
    }

    public function test_social(): void
    {
        $spec = ['kind' => 'social', 'max' => 2];
        $ok = [['network' => 'facebook', 'url' => 'https://example.test/fb'], ['network' => 'x', 'url' => ' https://example.test/x ']];

        $this->assertSame([[['network' => 'facebook', 'url' => 'https://example.test/fb'], ['network' => 'x', 'url' => 'https://example.test/x']], []], $this->check($ok, $spec));
        $this->assertSame([[], [['p', 'bad_list', []]]], $this->check('x', $spec));
        $this->assertSame([[], [['p.0.network', 'bad_choice', []]]], $this->check([['network' => 'myspace', 'url' => 'https://example.test']], $spec));
        $this->assertSame([[], [['p.0.url', 'bad_https_url', []]]], $this->check([['network' => 'facebook', 'url' => 'http://example.test']], $spec));
        $this->assertSame([[], [['p.0.url', 'bad_https_url', []]]], $this->check([['network' => 'facebook', 'url' => 'javascript:alert(1)']], $spec));
        $this->assertSame([[], [['p.0.url', 'bad_https_url', []]]], $this->check([['network' => 'facebook', 'url' => '/relative']], $spec), 'a path is not enough here: https only');

        $three = [...$ok, ['network' => 'tiktok', 'url' => 'https://example.test/tt']];
        [$kept, $fails] = $this->check($three, $spec);
        $this->assertCount(2, $kept);
        $this->assertSame([['p', 'too_many_slot', ['max' => 2]]], $fails);
        $this->assertSame([['p', 'too_many_social', ['max' => 2]]], $this->check($three, $spec + ['too_many_code' => 'too_many_social'])[1]);
    }

    public function test_payments_keep_the_catalog_methods_once_in_order(): void
    {
        $this->assertSame([['paypal', 'visa'], []], $this->check(['paypal', 'bitcoin', 'visa', 'paypal'], ['kind' => 'payments']));
        $this->assertSame([[], [['p', 'bad_list', []]]], $this->check('visa', ['kind' => 'payments']));
        $this->assertSame([[], []], $this->check([], ['kind' => 'payments']));
    }

    // ---- the kinds the Design screen adds -------------------------------------------------------------------------------------------

    public function test_color(): void
    {
        $this->assertSame(['#aabbcc', []], $this->check('#AABBCC', ['kind' => 'color']), 'lower case');
        $this->assertSame(['#abc', []], $this->check('#ABC', ['kind' => 'color']));
        $this->assertSame([null, []], $this->check('', ['kind' => 'color']));
        $this->assertSame([null, []], $this->check(null, ['kind' => 'color']));

        foreach (['red', '#12', '#12345', '#1234567', 'url(x)', '#gggggg', '#aabbcc;'] as $bad) {
            $this->assertSame([null, [['p', 'bad_color', []]]], $this->check($bad, ['kind' => 'color']), $bad);
        }
    }

    public function test_phone_goes_through_valid_phone(): void
    {
        $this->assertSame(['+359 888 123 456', []], $this->check('+359 888 123 456', ['kind' => 'phone']), 'an international number is checked in any country');
        $this->assertSame(['0888 123 456', []], $this->check('0888 123 456', ['kind' => 'phone', 'country' => 'BG']));
        $this->assertSame(['', [['p', 'bad_phone', []]]], $this->check('0888 12', ['kind' => 'phone', 'country' => 'BG']));
        $this->assertSame(['', [['p', 'bad_phone', []]]], $this->check('+359 12', ['kind' => 'phone']));
        $this->assertSame(['', []], $this->check('', ['kind' => 'phone']));
        $this->assertSame(['', [['p', 'required', []]]], $this->check('', ['kind' => 'phone', 'required' => true]));
        $this->assertSame(['', [['p', 'bad_phone', []]]], $this->check('0888 CALL NOW', ['kind' => 'phone', 'country' => 'BG']));
    }

    /** @return array<string, array{0: string}> */
    public static function idKinds(): array
    {
        return ['media' => ['media'], 'category' => ['category'], 'page' => ['page'], 'product' => ['product'], 'tag' => ['tag']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('idKinds')]
    public function test_the_target_kinds_are_positive_ids(string $kind): void
    {
        $this->assertSame([12, []], $this->check(12, ['kind' => $kind]));
        $this->assertSame([12, []], $this->check('12', ['kind' => $kind]));
        $this->assertSame([null, []], $this->check(null, ['kind' => $kind]));
        $this->assertSame([null, [['p', 'required', []]]], $this->check('', ['kind' => $kind, 'required' => true]));

        foreach ([0, -3, 'abc', 1.5, '1e3', [1]] as $bad) {
            $this->assertSame(['bad_choice'], $this->codes($bad, ['kind' => $kind]), json_encode($bad));
        }
    }

    public function test_attribute_is_a_stable_code(): void
    {
        $this->assertSame(['color', []], $this->check('color', ['kind' => 'attribute']));
        $this->assertSame(['shoe-size_2', []], $this->check('shoe-size_2', ['kind' => 'attribute']));
        $this->assertSame([null, []], $this->check('', ['kind' => 'attribute']));
        $this->assertSame([null, [['p', 'required', []]]], $this->check(null, ['kind' => 'attribute', 'required' => true]));

        foreach (['Color', '1color', 'a b', 'a/b', str_repeat('a', 65), 7] as $bad) {
            $this->assertSame(['bad_choice'], $this->codes($bad, ['kind' => 'attribute']), json_encode($bad));
        }
    }

    public function test_list_is_flat_and_bounded(): void
    {
        $spec = ['kind' => 'list', 'of' => 'html_block', 'max' => 3];

        $this->assertSame([[1, 2], []], $this->check([1, '2'], $spec));
        $this->assertSame([[], [['p', 'bad_list', []]]], $this->check('x', $spec));
        $this->assertSame([[], [['p', 'bad_list', []]]], $this->check(['a' => 1], $spec), 'a list, not an object');
        $this->assertSame([[1], [['p.1', 'bad_choice', []]]], $this->check([1, 'abc'], $spec), 'an item fails on its own path');

        [$kept, $fails] = $this->check([1, 2, 3, 4], $spec);
        $this->assertSame([1, 2, 3], $kept);
        $this->assertSame([['p', 'too_many_slot', ['max' => 3]]], $fails);

        $this->assertSame([['a', 'b'], []], $this->check(['a', 'b'], ['kind' => 'list', 'of' => ['kind' => 'attribute'], 'max' => 3]), 'of may be a spec');

        foreach (['list', 'social', 'payments', 'nonsense'] as $notFlat) {
            $this->assertSame(['unknown_setting'], $this->codes([1], ['kind' => 'list', 'of' => $notFlat]), $notFlat.': a list of lists is not allowed');
        }
    }

    public function test_an_unknown_kind_fails_and_defaults_follow_the_kind(): void
    {
        $this->assertSame([null, [['p', 'unknown_setting', []]]], $this->check('x', ['kind' => 'php']));

        $this->assertFalse(FieldKinds::default(['kind' => 'bool']));
        $this->assertSame(16, FieldKinds::default(['kind' => 'int', 'min' => 16]));
        $this->assertSame(0, FieldKinds::default(['kind' => 'int']));
        $this->assertSame('x', FieldKinds::default(['kind' => 'text', 'default' => 'x']));
        $this->assertSame('', FieldKinds::default(['kind' => 'text']));
        $this->assertSame([], FieldKinds::default(['kind' => 'social']));
        $this->assertSame([], FieldKinds::default(['kind' => 'list', 'of' => 'tag']));
        $this->assertNull(FieldKinds::default(['kind' => 'color']));
        $this->assertNull(FieldKinds::default(['kind' => 'media']));
        $this->assertCount(21, FieldKinds::KINDS);
    }
}
