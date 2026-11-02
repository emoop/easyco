<?php

namespace Tests\Unit\Storefront\PageDesign;

use App\Storefront\PageDesign\PageDesign;
use Tests\TestCase;

/**
 * CD-1d: the width of the category page's side panel, a closed choice of three (`wide` = the width it always had, `medium`, `narrow`): what is accepted on save, and what the storefront READS from a stored
 * row (old data without the key, and a hand-edited bad value, both give `wide` without disturbing the other choices).
 */
class CatalogSideWidthTest extends TestCase
{
    public function test_the_three_widths_are_the_closed_list_and_wide_is_the_default(): void
    {
        $this->assertSame(['wide', 'medium', 'narrow'], PageDesign::CATALOG_SIDE_WIDTHS);
        $this->assertSame('wide', PageDesign::defaults(PageDesign::CATALOG)['sideWidth']);
    }

    /** @return array<string, array{0: string}> */
    public static function goodWidths(): array
    {
        return ['wide' => ['wide'], 'medium' => ['medium'], 'narrow' => ['narrow']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goodWidths')]
    public function test_each_of_the_three_is_accepted_on_save(string $width): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, ['sideWidth' => $width], false);

        $this->assertSame([], $errors);
        $this->assertSame($width, $clean['sideWidth']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badWidths(): array
    {
        return [
            'array' => [['wide']], 'empty' => [''], 'upper case' => ['WIDE'], 'a length' => ['20rem'], 'a declaration' => ['wide;color:red'], 'null' => [null], 'a number' => [15], 'a word' => ['huge'], 'long' => [str_repeat('w', 600)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badWidths')]
    public function test_anything_else_is_refused_on_save_and_the_default_is_kept(mixed $value): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, ['sideWidth' => $value], false);

        $this->assertArrayHasKey('sideWidth', $errors);
        $this->assertSame('wide', $clean['sideWidth']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badWidths')]
    public function test_a_bad_stored_width_is_read_as_wide_and_keeps_the_other_choices(mixed $value): void
    {
        $values = PageDesign::catalogValues((string) json_encode(['layout' => 'sidebar', 'side' => 'right', 'cols' => '5', 'sideWidth' => $value]));

        $this->assertSame('wide', $values['sideWidth']);
        $this->assertSame('sidebar', $values['layout'], 'a bad width does not turn the side panel into the bar');
        $this->assertSame('right', $values['side']);
        $this->assertSame('5', $values['cols']);
    }

    public function test_old_stored_data_without_the_key_is_read_as_wide(): void
    {
        $values = PageDesign::catalogValues('{"cols":"4","cols_phone":"2","per_page":"24","layout":"sidebar","side":"left"}');

        $this->assertSame('wide', $values['sideWidth']);
        $this->assertSame('sidebar', $values['layout']);
        $this->assertSame('wide', PageDesign::catalogValues(null)['sideWidth']);
    }

    public function test_a_stored_width_is_read_back(): void
    {
        foreach (['medium', 'narrow'] as $width) {
            $this->assertSame($width, PageDesign::catalogValues((string) json_encode(['layout' => 'sidebar', 'sideWidth' => $width]))['sideWidth']);
        }
    }

    public function test_the_width_is_a_class_and_no_css_variable_is_written_for_it(): void
    {
        $this->assertSame([], PageDesign::vars(PageDesign::CATALOG, ['sideWidth' => 'narrow'] + PageDesign::defaults(PageDesign::CATALOG)));
    }

    public function test_another_problem_in_the_row_still_gives_the_defaults_as_before(): void
    {
        $values = PageDesign::catalogValues('{"layout":"sidebar","cols":"9","sideWidth":"narrow"}');

        $this->assertSame(PageDesign::defaults(PageDesign::CATALOG), $values);
    }
}
