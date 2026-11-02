<?php

namespace Tests\Unit\Storefront\PageDesign;

use App\Storefront\PageDesign\PageDesign;
use Tests\TestCase;

/**
 * CT-4: the three closed choices of the «Каталог» design for the built-in filters (`filterBrand`, `filterSeason`, `filterTag`: off | list | links | buttons): their defaults, what is accepted on save, and what the
 * storefront READS from a stored row (old data without the keys, a hand-edited bad value: that key alone falls back to its own default and the other choices are not disturbed).
 */
class CatalogBuiltinFilterDesignTest extends TestCase
{
    private const KEYS = ['filterBrand' => 'list', 'filterSeason' => 'list', 'filterTag' => 'off'];

    public function test_the_closed_list_and_the_defaults(): void
    {
        $this->assertSame(['off', 'list', 'links', 'buttons'], PageDesign::CATALOG_FILTER_KINDS);

        foreach (self::KEYS as $key => $default) {
            $this->assertSame($default, PageDesign::defaults(PageDesign::CATALOG)[$key], $key);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function goodChoices(): array
    {
        $good = [];

        foreach (array_keys(self::KEYS) as $key) {
            foreach (['off', 'list', 'links', 'buttons'] as $kind) {
                $good["$key $kind"] = [$key, $kind];
            }
        }

        return $good;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goodChoices')]
    public function test_each_choice_of_the_closed_list_is_accepted_on_save(string $key, string $kind): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, [$key => $kind], false);

        $this->assertSame([], $errors);
        $this->assertSame($kind, $clean[$key]);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function badChoices(): array
    {
        $bad = [];

        foreach (array_keys(self::KEYS) as $key) {
            foreach (['array' => ['list'], 'empty' => '', 'upper case' => 'LIST', 'a length' => '20rem', 'null' => null, 'a number' => 1, 'a word' => 'chips', 'declaration' => 'list;color:red', 'long' => str_repeat('b', 600)] as $label => $value) {
                $bad["$key $label"] = [$key, $value];
            }
        }

        return $bad;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badChoices')]
    public function test_anything_else_is_refused_on_save_and_the_default_is_kept(string $key, mixed $value): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, [$key => $value], false);

        $this->assertArrayHasKey($key, $errors);
        $this->assertSame(self::KEYS[$key], $clean[$key]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badChoices')]
    public function test_a_bad_stored_choice_is_read_as_that_keys_default_and_the_other_choices_are_kept(string $key, mixed $value): void
    {
        $values = PageDesign::catalogValues((string) json_encode(['layout' => 'sidebar', 'side' => 'right', 'cols' => '5', 'filterBrand' => 'buttons', 'filterSeason' => 'links', 'filterTag' => 'links', $key => $value]));

        $this->assertSame(self::KEYS[$key], $values[$key]);
        $this->assertSame('sidebar', $values['layout'], 'a bad choice does not turn the side panel into the bar');
        $this->assertSame('5', $values['cols']);

        foreach (array_diff(array_keys(self::KEYS), [$key]) as $other) {
            $this->assertSame(['filterBrand' => 'buttons', 'filterSeason' => 'links', 'filterTag' => 'links'][$other], $values[$other], "$other is not disturbed");
        }
    }

    public function test_old_stored_data_without_the_keys_reads_the_defaults(): void
    {
        $values = PageDesign::catalogValues('{"cols":"4","cols_phone":"2","per_page":"24","layout":"sidebar","side":"left","sideWidth":"narrow"}');

        foreach (self::KEYS as $key => $default) {
            $this->assertSame($default, $values[$key], $key);
        }

        $this->assertSame('narrow', $values['sideWidth']);
        $this->assertSame('sidebar', $values['layout']);
    }

    public function test_another_problem_in_the_row_still_gives_the_defaults_as_before_and_no_css_variable_is_written(): void
    {
        $this->assertSame(PageDesign::defaults(PageDesign::CATALOG), PageDesign::catalogValues('{"cols":"9","filterTag":"buttons"}'));
        $this->assertSame([], PageDesign::vars(PageDesign::CATALOG, ['filterTag' => 'buttons'] + PageDesign::defaults(PageDesign::CATALOG)));
    }
}
