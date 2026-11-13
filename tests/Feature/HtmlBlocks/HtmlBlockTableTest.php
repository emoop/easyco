<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\Sections\SectionSet;
use App\Storefront\Sections\SectionStore;
use App\Storefront\Support\DescriptionSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * TBL-1: tables in an HTML block (size tables). Why they could not be made: BOTH the editor had no table buttons AND the filter, though it kept the table tags, removed every attribute (a merged cell lost its
 * colspan) and had no tfoot / caption / colgroup / col. Now the filter keeps the table tags and `colspan` / `rowspan` (1..20) / `scope` (row | col) on cells and nothing else, `render()` wraps each outermost table
 * in `.sf-table-wrap` when it is PRINTED (never stored), and a block without a table prints exactly as before.
 */
class HtmlBlockTableTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const SIZES = '66666666-6666-4666-8666-666666666666';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'price' => '10.00', 'stock' => 3]);
    }

    private const TABLE = '<table><thead><tr><th scope="col">Size</th><th>Chest</th></tr></thead><tbody><tr><th scope="row">S</th><td>90</td></tr><tr><td colspan="2">One cell</td></tr></tbody></table>';

    // ---- the filter ---------------------------------------------------------------------------------------------------------------------

    /** ★ a table survives, with its header cell scopes and spans, and the other table tags are kept */
    public function test_a_table_survives(): void
    {
        $this->assertSame(self::TABLE, HtmlBlockSanitizer::clean(self::TABLE));

        $all = '<table><caption>Sizes</caption><colgroup><col><col></colgroup><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td rowspan="2">1</td><td>2</td></tr><tr><td>3</td></tr></tbody><tfoot><tr><td colspan="2">Foot</td></tr></tfoot></table>';
        $this->assertSame($all, HtmlBlockSanitizer::clean($all));
    }

    /** @return array<string, array{0: string, 1: string}> input, a fragment that must NOT survive */
    public static function dangerous(): array
    {
        return [
            'style on a table' => ['<table style="width:900px"><tr><td>1</td></tr></table>', 'style'],
            'style on a cell' => ['<table><tr><td style="color:red">1</td></tr></table>', 'style'],
            'class on a cell' => ['<table><tr><td class="x">1</td></tr></table>', 'class='],
            'width on a table' => ['<table width="100%"><tr><td>1</td></tr></table>', 'width'],
            'width on a cell' => ['<table><tr><td width="50">1</td></tr></table>', 'width'],
            'border on a table' => ['<table border="1"><tr><td>1</td></tr></table>', 'border'],
            'bgcolor on a cell' => ['<table><tr><td bgcolor="#f00">1</td></tr></table>', 'bgcolor'],
            'align on a cell' => ['<table><tr><td align="right">1</td></tr></table>', 'align'],
            'onclick on a cell' => ['<table><tr><td onclick="x()">1</td></tr></table>', 'onclick'],
            'onmouseover on a row' => ['<table><tr onmouseover="x()"><td>1</td></tr></table>', 'onmouseover'],
            'data on a table' => ['<table data-x="1"><tr><td>1</td></tr></table>', 'data-x'],
            'id on a table' => ['<table id="t"><tr><td>1</td></tr></table>', 'id='],
            'width on a col' => ['<table><colgroup><col width="10" style="width:10px"></colgroup><tr><td>1</td></tr></table>', 'width'],
            'a script in a cell' => ['<table><tr><td>ok<script>alert(1)</script></td></tr></table>', 'script'],
            'an iframe in a cell' => ['<table><tr><td><iframe src="https://example.com"></iframe></td></tr></table>', 'iframe'],
            'span on a table' => ['<table colspan="2"><tr><td>1</td></tr></table>', 'colspan'],
            'colspan on a tr' => ['<table><tr colspan="2"><td>1</td></tr></table>', 'colspan'],
            'scope on a td' => ['<table><tr><td scope="row">1</td></tr></table>', 'scope'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_forbidden_attributes_and_content_are_removed(string $input, string $forbidden): void
    {
        $out = HtmlBlockSanitizer::clean($input);

        $this->assertStringNotContainsString($forbidden, $out);
        $this->assertStringContainsString('<td', $out.'<td', 'the table is still a table');
        $this->assertSame($out, HtmlBlockSanitizer::clean($out), 'idempotent');
    }

    /** @return array<string, array{0: string, 1: ?string}> the colspan value, what stays */
    public static function spans(): array
    {
        return [
            '1' => ['1', '1'], '20' => ['20', '20'], '07' => ['07', '7'], ' 3 ' => [' 3 ', '3'],
            '21' => ['21', null], '999' => ['999', null], '0' => ['0', null], 'x' => ['x', null], 'negative' => ['-2', null], 'decimal' => ['2.5', null], 'empty' => ['', null], 'script' => ['2<script>', null],
        ];
    }

    /** ★ colspan / rowspan are whole numbers 1..20; anything else is dropped (the cell stays) */
    #[DataProvider('spans')]
    public function test_spans_are_whole_numbers_from_1_to_20(string $value, ?string $kept): void
    {
        foreach (['colspan', 'rowspan'] as $span) {
            $out = HtmlBlockSanitizer::clean('<table><tr><td '.$span.'="'.htmlspecialchars($value, ENT_QUOTES).'">x</td></tr></table>');

            $this->assertSame($kept === null ? '<table><tr><td>x</td></tr></table>' : '<table><tr><td '.$span.'="'.$kept.'">x</td></tr></table>', $out, $span.' = '.$value);
        }
    }

    public function test_scope_is_row_or_col_on_a_header_cell_only(): void
    {
        $this->assertSame('<table><tr><th scope="row">x</th></tr></table>', HtmlBlockSanitizer::clean('<table><tr><th scope="ROW">x</th></tr></table>'));
        $this->assertSame('<table><tr><th>x</th></tr></table>', HtmlBlockSanitizer::clean('<table><tr><th scope="rowgroup">x</th></tr></table>'));
        $this->assertSame('<table><tr><th>x</th></tr></table>', HtmlBlockSanitizer::clean('<table><tr><th scope="javascript:1">x</th></tr></table>'));
    }

    /** a table inside a cell: the walker nests any allowed element (nothing forbids it for the other blocks either), so it stays, cleaned as the outer one */
    public function test_a_nested_table_is_cleaned_like_the_outer_one(): void
    {
        $out = HtmlBlockSanitizer::clean('<table><tr><td><table style="x"><tr><td onclick="y" colspan="2">in</td></tr></table></td></tr></table>');

        $this->assertSame('<table><tr><td><table><tr><td colspan="2">in</td></tr></table></td></tr></table>', $out);
    }

    /** a cell from a spreadsheet / TipTap: the paragraphs inside cells, a colgroup with widths and `colspan="1" rowspan="1"` are kept as far as they are allowed */
    public function test_a_pasted_table_keeps_its_structure(): void
    {
        $pasted = '<table style="min-width: 75px"><colgroup><col style="min-width: 25px"><col></colgroup><tbody><tr><th colspan="1" rowspan="1" colwidth="25"><p>S</p></th><td colspan="1" rowspan="1" class="c"><p>90</p></td></tr></tbody></table>';

        $this->assertSame('<table><colgroup><col><col></colgroup><tbody><tr><th colspan="1" rowspan="1"><p>S</p></th><td colspan="1" rowspan="1"><p>90</p></td></tr></tbody></table>', HtmlBlockSanitizer::clean($pasted));
    }

    // ---- the print wrapper ----------------------------------------------------------------------------------------------------------------

    /** ★ render() wraps each OUTERMOST table once, is idempotent, and `clean()` (the save) never stores the wrapper */
    public function test_the_wrapper_is_added_when_printed_not_stored(): void
    {
        $once = HtmlBlockSanitizer::render('<p>a</p>'.self::TABLE.'<p>b</p>'.self::TABLE);

        $this->assertSame(2, substr_count($once, '<div class="sf-table-wrap"><table>'));
        $this->assertSame(2, substr_count($once, '</table></div>'));
        $this->assertSame($once, HtmlBlockSanitizer::render($once), 'idempotent: printed HTML cleaned again is wrapped once');
        $this->assertStringNotContainsString('sf-table-wrap', HtmlBlockSanitizer::clean($once), 'clean() drops the unknown div: the wrapper is never part of what is stored');

        $block = HtmlBlockModel::query()->create(['title' => 'Sizes', 'body' => $once]);
        $this->assertStringNotContainsString('sf-table-wrap', (string) $block->fresh()->body);
        $this->assertStringContainsString('<table>', (string) $block->fresh()->body);

        $nested = HtmlBlockSanitizer::render('<table><tr><td><table><tr><td>in</td></tr></table></td></tr></table>');
        $this->assertSame(1, substr_count($nested, 'sf-table-wrap'), 'a table inside a cell is already inside its parent\'s box');
    }

    /** ★ a block WITHOUT a table prints byte for byte as before: the wrapper code is never reached */
    public function test_a_block_without_a_table_is_unchanged(): void
    {
        $html = '<h2>Title</h2><p>Text <strong>bold</strong></p><ul><li>a</li></ul><p><a href="https://example.com">x</a></p>';

        $this->assertSame(HtmlBlockSanitizer::clean($html), HtmlBlockSanitizer::render($html));
        $this->assertStringNotContainsString('sf-table-wrap', HtmlBlockSanitizer::render($html));
    }

    /** the description filter is NOT changed: a table keeps no attribute there, as before */
    public function test_the_description_filter_is_untouched(): void
    {
        $this->assertSame('<table><tr><td>x</td></tr></table>', DescriptionSanitizer::clean('<table><tr><td colspan="2" style="x">x</td></tr></table>'));
        $this->assertSame('x<p>x</p>', DescriptionSanitizer::clean('<caption>x</caption><p>x</p>'), 'a caption is still an unknown element there: unwrapped');
    }

    // ---- printed in a product section ----------------------------------------------------------------------------------------------------

    private function sizesSection(string $body): void
    {
        $block = (int) HtmlBlockModel::query()->create(['title' => 'Sizes', 'body' => $body])->id;
        [$clean, $errors] = SectionSet::validate(['sections' => [['key' => self::SIZES, 'title' => 'Sizes', 'source' => 'blocks', 'blocks' => [$block]]]]);
        $this->assertSame([], $errors, json_encode($errors));
        SectionStore::publish($clean);

        $layout = ProductSummaryLayout::default();
        $layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => false]];
        app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        $this->app->forgetScopedInstances();
    }

    /** ★ a block with a table prints inside `sf-table-wrap` in a product section (no forbidden attribute reaches the page); a block without a table has no wrapper */
    public function test_a_table_block_prints_inside_the_wrapper_in_a_product_section(): void
    {
        $this->sizesSection('<p>Size guide</p><table style="width:900px" onclick="x()"><thead><tr><th scope="col" class="c">Size</th><th>Chest</th></tr></thead><tbody><tr><th scope="row">S</th><td width="40">90</td></tr></tbody></table>');

        $html = $this->get('/product/p1')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="sf-psec-block"><p>Size guide</p><div class="sf-table-wrap"><table><thead><tr><th scope="col">Size</th><th>Chest</th></tr></thead><tbody><tr><th scope="row">S</th><td>90</td></tr></tbody></table></div></div>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('width:900px', $html);

        $this->sizesSection('<p>Plain guide</p>');
        $plain = $this->get('/product/p1')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="sf-psec-block"><p>Plain guide</p></div>', $plain);
        $this->assertStringNotContainsString('sf-table-wrap', $plain);
    }
}
