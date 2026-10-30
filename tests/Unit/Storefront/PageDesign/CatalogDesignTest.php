<?php

namespace Tests\Unit\Storefront\PageDesign;

use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\Support\PaginationLinks;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * CD-1a: the «Каталог» section (columns on a desktop and on a phone, products per page): its closed lists, the variables that are written (only what differs from the default), the closed list the
 * token compiler accepts, and the numbered pagination row (first, last, current ± 2, an ellipsis for a gap).
 */
class CatalogDesignTest extends TestCase
{
    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validate(array $values): array
    {
        return PageDesign::validate(PageDesign::CATALOG, $values, false);
    }

    public function test_the_defaults_are_todays_values(): void
    {
        $this->assertSame(['cols' => '4', 'cols_phone' => '2', 'per_page' => '24'], PageDesign::defaults(PageDesign::CATALOG));
        $this->assertSame([], PageDesign::vars(PageDesign::CATALOG, PageDesign::defaults(PageDesign::CATALOG)), 'a shop that never touched it writes no variable');
        $this->assertTrue(PageDesign::isDefault(PageDesign::CATALOG, PageDesign::defaults(PageDesign::CATALOG)));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function badValues(): array
    {
        return [
            'columns 2' => ['cols', '2'], 'columns 6' => ['cols', '6'], 'columns 0' => ['cols', '0'], 'columns int 5' => ['cols', 5], 'columns text' => ['cols', 'five'], 'columns declaration' => ['cols', '4;color:red'],
            'columns 600 digits' => ['cols', str_repeat('9', 600)], 'columns float' => ['cols', '4.5'], 'columns empty' => ['cols', ''], 'columns null' => ['cols', null], 'columns array' => ['cols', ['4']],
            'phone 0' => ['cols_phone', '0'], 'phone 3' => ['cols_phone', '3'], 'phone declaration' => ['cols_phone', '1;color:red'], 'phone 600 digits' => ['cols_phone', str_repeat('1', 600)],
            'per page 10' => ['per_page', '10'], 'per page 100' => ['per_page', '100'], 'per page 0' => ['per_page', '0'], 'per page text' => ['per_page', 'all'], 'per page float' => ['per_page', '24.5'],
            'per page declaration' => ['per_page', '24;color:red'], 'per page 600 digits' => ['per_page', str_repeat('9', 600)], 'per page negative' => ['per_page', '-24'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function test_a_value_outside_the_closed_list_is_refused_and_the_default_is_kept(string $field, mixed $value): void
    {
        [$clean, $errors] = $this->validate([$field => $value]);

        $this->assertArrayHasKey($field, $errors);
        $this->assertSame(PageDesign::defaults(PageDesign::CATALOG)[$field], $clean[$field]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function goodValues(): array
    {
        $good = [];

        foreach (['cols' => ['3', '4', '5'], 'cols_phone' => ['1', '2'], 'per_page' => ['12', '24', '36', '48', '60', '72']] as $field => $values) {
            foreach ($values as $value) {
                $good["$field $value"] = [$field, $value];
            }
        }

        return $good;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goodValues')]
    public function test_every_member_of_a_closed_list_is_accepted(string $field, string $value): void
    {
        [$clean, $errors] = $this->validate([$field => $value]);

        $this->assertSame([], $errors);
        $this->assertSame($value, $clean[$field]);
    }

    public function test_an_unknown_key_is_refused_and_an_empty_list_is_the_defaults(): void
    {
        $this->assertArrayHasKey('layout', $this->validate(['layout' => 'sidebar'])[1]);
        $this->assertArrayHasKey('side', $this->validate(['side' => 'left'])[1], 'the layout and side-panel controls come with the filters');
        $this->assertSame([[], PageDesign::defaults(PageDesign::CATALOG)], [$this->validate([])[1], $this->validate([])[0]]);
    }

    public function test_only_what_differs_from_the_defaults_becomes_a_variable_and_the_per_page_never_does(): void
    {
        $vars = static fn (array $values): array => PageDesign::vars(PageDesign::CATALOG, array_replace(PageDesign::defaults(PageDesign::CATALOG), $values));

        $this->assertSame(['--sf-cat-cols' => '5'], $vars(['cols' => '5']));
        $this->assertSame(['--sf-cat-cols' => '3'], $vars(['cols' => '3']));
        $this->assertSame(['--sf-cat-cols-phone' => '1'], $vars(['cols_phone' => '1']));
        $this->assertSame([], $vars(['per_page' => '48']), 'the per-page size is read at render, it is not a variable');
        $this->assertSame([], $vars(['cols' => '4', 'cols_phone' => '2']), 'the defaults write nothing');
        $this->assertFalse(PageDesign::isDefault(PageDesign::CATALOG, array_replace(PageDesign::defaults(PageDesign::CATALOG), ['per_page' => '48'])), 'but it IS stored');
    }

    public function test_the_closed_list_of_variables_and_the_compiler(): void
    {
        foreach (['3', '4', '5'] as $value) {
            $this->assertTrue(PageDesign::isValidVar('--sf-cat-cols', $value));
        }

        foreach (['2', '6', '0', '', '4;color:red', '4 ', 4, null, '05'] as $value) {
            $this->assertFalse(PageDesign::isValidVar('--sf-cat-cols', $value), var_export($value, true));
        }

        $this->assertTrue(PageDesign::isValidVar('--sf-cat-cols-phone', '1'));
        $this->assertTrue(PageDesign::isValidVar('--sf-cat-cols-phone', '2'));

        foreach (['0', '3', '1;color:red', ''] as $value) {
            $this->assertFalse(PageDesign::isValidVar('--sf-cat-cols-phone', $value));
        }

        $css = (new AppearanceCompiler())->css([], [], [], [], [], [], ['--sf-cat-cols' => '5', '--sf-cat-cols-phone' => '1']);
        $this->assertStringContainsString('--sf-cat-cols: 5;', (string) $css);
        $this->assertStringContainsString('--sf-cat-cols-phone: 1;', (string) $css);

        $this->expectException(InvalidArgumentException::class);
        (new AppearanceCompiler())->css([], [], [], [], [], [], ['--sf-cat-cols' => '6']);
    }

    public function test_the_stored_json_that_does_not_validate_gives_the_defaults(): void
    {
        $this->assertSame(PageDesign::defaults(PageDesign::CATALOG), PageDesign::catalogValues(null));
        $this->assertSame(PageDesign::defaults(PageDesign::CATALOG), PageDesign::catalogValues('not json'));
        $this->assertSame(PageDesign::defaults(PageDesign::CATALOG), PageDesign::catalogValues('{"per_page":"7"}'));
        $this->assertSame(PageDesign::defaults(PageDesign::CATALOG), PageDesign::catalogValues('{"per_page":"48","evil":"1"}'));
        $this->assertSame('48', PageDesign::catalogValues('{"per_page":"48"}')['per_page']);
    }

    // ---- the numbered row ---------------------------------------------------------------------------------------------------------------

    /** @return list<string> the row as text: a number, `[n]` for the current page, `…` for a gap */
    private function row(int $current, int $last): array
    {
        $links = (new PaginationLinks())->build('/x', new ListingPage(['sort' => 'newest'], [], $current, 24, 24 * $last, $last, []));

        return array_map(static fn (array $page): string => isset($page['ellipsis']) ? '…' : ($page['current'] ? '['.$page['number'].']' : (string) $page['number']), $links['pages']);
    }

    /** @return array<string, array{0: int, 1: int, 2: list<string>}> */
    public static function rows(): array
    {
        return [
            '1 page' => [1, 1, ['[1]']],
            '5 pages, first' => [1, 5, ['[1]', '2', '3', '4', '5']],
            '5 pages, middle' => [3, 5, ['1', '2', '[3]', '4', '5']],
            '5 pages, last' => [5, 5, ['1', '2', '3', '4', '[5]']],
            '7 pages, middle (all fit)' => [4, 7, ['1', '2', '3', '[4]', '5', '6', '7']],
            '7 pages, first' => [1, 7, ['[1]', '2', '3', '4', '5', '6', '7']],
            '20 pages, first' => [1, 20, ['[1]', '2', '3', '…', '20']],
            '20 pages, second' => [2, 20, ['1', '[2]', '3', '4', '…', '20']],
            '20 pages, fourth (a gap of one page is its number)' => [4, 20, ['1', '2', '3', '[4]', '5', '6', '…', '20']],
            '20 pages, middle' => [10, 20, ['1', '…', '8', '9', '[10]', '11', '12', '…', '20']],
            '20 pages, before the end' => [18, 20, ['1', '…', '16', '17', '[18]', '19', '20']],
            '20 pages, last' => [20, 20, ['1', '…', '18', '19', '[20]']],
            '8 pages, first' => [1, 8, ['[1]', '2', '3', '…', '8']],
            '8 pages, middle (the gap of one page is its number)' => [5, 8, ['1', '2', '3', '4', '[5]', '6', '7', '8']],
        ];
    }

    /** @param  list<string>  $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('rows')]
    public function test_the_numbered_row(int $current, int $last, array $expected): void
    {
        $this->assertSame($expected, $this->row($current, $last));
    }

    public function test_the_links_keep_the_size_and_the_sort_and_the_first_page_has_no_page_key(): void
    {
        $links = (new PaginationLinks())->build('/x', new ListingPage(['sort' => 'name_asc'], [], 2, 12, 100, 9, []));

        $this->assertSame('/x?per_page=12&sort=name_asc', $links['prev'], 'page 1 has no `page`');
        $this->assertSame('/x?page=3&per_page=12&sort=name_asc', $links['next']);

        $published = (new PaginationLinks())->build('/x', new ListingPage(['sort' => 'newest'], [], 2, 48, 100, 9, []), 48);
        $this->assertSame('/x', $published['prev'], 'the published size is the default: it is left out');
        $this->assertSame('/x?page=3', $published['next']);

        $other = (new PaginationLinks())->build('/x', new ListingPage(['sort' => 'newest'], [], 2, 24, 100, 9, []), 48);
        $this->assertSame('/x?per_page=24', $other['prev'], 'a size that is not the published one is kept in the link');
    }
}
