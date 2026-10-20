<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuException;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuTree;
use App\Content\Menus\MenuValidator;
use App\Content\Menus\MenuWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * MENU-3: the columns of a mega item. A mega item holds EXACTLY the number of columns it says (1 to 4); a column has an optional heading and its links, so the same model serves the
 * 2-level menu (the boutique: no headings) and the 3-level one (the children's shop: a heading on every column). What the storefront prints: that many equal columns, the HTML block
 * to the right as before, an empty column keeps its place; the phone drawer shows the columns as sections one under another.
 */
class MegaColumnsTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('en');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');

        foreach (range(2, 13) as $id) {
            $this->makeCategory($id, "Cat {$id}", "cat-{$id}", 1);
            $this->makeProduct(100 + $id, ['slug' => "p-{$id}", 'categories' => [$id], 'price' => '5.00', 'stock' => 1]);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function link(string $label, string $url, array $extra = []): array
    {
        return $this->menuItem('link', ['label' => $label, 'url' => $url] + $extra);
    }

    private function block(): HtmlBlockModel
    {
        return HtmlBlockModel::create(['title' => 'Picture', 'body' => '<figure><img src="/storage/html-blocks/p.jpg" alt="P" width="300" height="200"></figure>']);
    }

    /** @return list<array<string, mixed>> twelve category items, one per category 2..13 */
    private function twelveCategories(): array
    {
        return array_map(fn (int $id): array => $this->menuItem('category', ['target_id' => $id]), range(2, 13));
    }

    // ---- the rules of the validator -----------------------------------------------------------------------------------------------------------

    public function test_a_mega_item_must_hold_exactly_as_many_columns_as_it_says(): void
    {
        foreach ([[3, 2], [1, 2], [2, 0], [4, 3]] as [$says, $has]) {
            $validation = MenuValidator::validate([$this->link('M', '/m', ['submenu' => 'mega', 'mega_columns' => $says, 'children' => array_fill(0, $has, $this->columnItem())])]);

            $this->assertFalse($validation->passes(), "says {$says}, has {$has}");
            $this->assertArrayHasKey('items.0.children', $validation->errors, "says {$says}, has {$has}");
            $this->assertStringContainsString("exactly {$says} columns", $validation->errors['items.0.children']);
        }

        $this->assertTrue(MenuValidator::validate([$this->megaItem('M', '/m', 2, [$this->columnItem([$this->link('A', '/a')]), $this->columnItem()])])->passes(), 'an empty column is a column');
    }

    public function test_five_columns_are_refused_and_so_is_a_number_outside_one_to_four(): void
    {
        $five = MenuValidator::validate([$this->link('M', '/m', ['submenu' => 'mega', 'mega_columns' => 4, 'children' => array_fill(0, 5, $this->columnItem())])]);
        $this->assertArrayHasKey('items.0.children', $five->errors);
        $this->assertStringContainsString('at most 4', $five->errors['items.0.children']);

        foreach ([0, 5, 6, 'x'] as $bad) {
            $validation = MenuValidator::validate([$this->link('M', '/m', ['submenu' => 'mega', 'mega_columns' => $bad, 'children' => array_fill(0, 4, $this->columnItem())])]);

            $this->assertArrayHasKey('items.0.mega_columns', $validation->errors, (string) $bad);
        }

        $this->assertSame(4, MenuValidator::MAX_COLUMNS);
    }

    public function test_a_column_stands_only_directly_under_a_mega_item_and_a_mega_item_holds_nothing_else(): void
    {
        $this->assertArrayHasKey('items.0.type', MenuValidator::validate([$this->columnItem()])->errors);
        $this->assertArrayHasKey('items.0.children.0.type', MenuValidator::validate([$this->link('D', '/d', ['submenu' => 'dropdown', 'children' => [$this->columnItem()]])])->errors);
        $this->assertArrayHasKey('items.0.children.0.type', MenuValidator::validate([$this->link('M', '/m', ['submenu' => 'mega', 'mega_columns' => 1, 'children' => [$this->link('Direct', '/d')]])])->errors, 'a link directly under a mega item');
        $this->assertStringContainsString('directly under', MenuValidator::validate([$this->columnItem()])->errors['items.0.type']);
    }

    public function test_a_column_has_an_optional_heading_up_to_sixty_characters_and_nothing_else(): void
    {
        $this->assertTrue(MenuValidator::validate([$this->megaItem('M', '/m', 1, [$this->columnItem([], str_repeat('h', 60))])])->passes());
        $this->assertArrayHasKey('items.0.children.0.label', MenuValidator::validate([$this->megaItem('M', '/m', 1, [$this->columnItem([], str_repeat('h', 61))])])->errors);
        $this->assertArrayHasKey('items.0.children.0.label', MenuValidator::validate([$this->megaItem('M', '/m', 1, [$this->columnItem([], "Bad\u{202E}")])])->errors);

        foreach (['url' => '/x', 'target_id' => 3, 'badge_text' => 'New'] as $field => $value) {
            $this->assertArrayHasKey("items.0.children.0.{$field}", MenuValidator::validate([$this->megaItem('M', '/m', 1, [$this->columnItem() + [$field => $value]])])->errors, $field);
        }

        $this->assertArrayHasKey('items.0.children.0.children', MenuValidator::validate([$this->megaItem('M', '/m', 1, [$this->columnItem(array_map(fn (int $i): array => $this->link("L{$i}", "/l{$i}"), range(1, 16)))])])->errors, 'at most 15 links');
    }

    public function test_the_writer_stores_the_number_of_columns_and_refuses_a_wrong_tree_without_writing(): void
    {
        $menu = MenuModel::query()->where('key', 'main')->firstOrFail();
        app(MenuWriter::class)->save($menu, 'Main', [$this->megaItem('Dresses', '/d', 4, [$this->columnItem(array_slice($this->twelveCategories(), 0, 3))])]);

        $tree = MenuTree::forMenu($menu->id);
        $this->assertSame([4, 4], [$tree[0]['mega_columns'], count($tree[0]['children'])]);
        $this->assertSame([3, 0, 0, 0], array_map(fn (array $column): int => count($column['children']), $tree[0]['children']));

        try {
            app(MenuWriter::class)->save($menu, 'Changed', [$this->link('M', '/m', ['submenu' => 'mega', 'mega_columns' => 3, 'children' => array_fill(0, 2, $this->columnItem())])]);
            $this->fail('refused');
        } catch (MenuException $exception) {
            $this->assertArrayHasKey('items.0.children', $exception->errors);
        }

        $this->assertSame(4, MenuTree::forMenu($menu->id)[0]['mega_columns'], 'nothing was written');
        $this->assertSame('Main', $menu->fresh()->name);
    }

    // ---- the storefront ---------------------------------------------------------------------------------------------------------------------

    public function test_the_boutique_case_four_columns_without_headings_and_the_block(): void
    {
        $cats = $this->twelveCategories();
        $this->saveMenu('main', [$this->megaItem('Dresses', '/dresses', 4, [
            $this->columnItem(array_slice($cats, 0, 3)), $this->columnItem(array_slice($cats, 3, 3)), $this->columnItem(array_slice($cats, 6, 3)), $this->columnItem(array_slice($cats, 9, 3)),
        ], ['html_block_id' => $this->block()->id])]);

        $html = $this->get('/')->assertOk()->getContent();
        $panel = substr($html, (int) strpos($html, 'class="sf-sub sf-sub-mega"'));

        $this->assertStringContainsString('<div class="sf-mega-cols" data-cols="4">', $panel);
        $this->assertSame(4, substr_count($panel, 'class="sf-mega-col"'));
        $this->assertSame(0, substr_count(substr($panel, 0, (int) strpos($panel, 'sf-mega-media')), 'sf-mega-heading'), 'no heading anywhere: the 2-level case is just links');
        $this->assertSame(12, substr_count(substr($panel, 0, (int) strpos($panel, 'sf-mega-media')), 'class="sf-menu-link"'));
        $this->assertStringContainsString('<div class="sf-mega-media sf-mega-html"><figure><img loading="lazy" decoding="async" src="/storage/html-blocks/p.jpg" alt="P" width="300" height="200"></figure></div>', $panel, 'the block column is unchanged');
        $this->assertNoActiveContent(preg_replace('#<form[ >].*?</form>#s', '', $html), 'mega menu');
    }

    public function test_the_childrens_shop_case_three_columns_with_headings_and_the_block(): void
    {
        $cats = $this->twelveCategories();
        $this->saveMenu('main', [$this->megaItem('Boys', '/boys', 3, [
            $this->columnItem(array_slice($cats, 0, 3), 'Clothes'), $this->columnItem(array_slice($cats, 3, 2), 'Clothes'), $this->columnItem(array_slice($cats, 5, 2), 'Shoes'),
        ], ['html_block_id' => $this->block()->id])]);

        $html = $this->get('/')->assertOk()->getContent();
        $panel = substr($html, (int) strpos($html, 'class="sf-sub sf-sub-mega"'));

        $this->assertStringContainsString('<div class="sf-mega-cols" data-cols="3">', $panel);
        $this->assertSame(['Clothes', 'Clothes', 'Shoes'], $this->headings(substr($panel, 0, (int) strpos($panel, 'sf-mega-media'))));
        $this->assertMatchesRegularExpression('#<div class="sf-mega-col">\s*<p class="sf-mega-heading">Clothes</p>\s*<ul class="sf-menu">#', $panel, 'the heading on top, the links under it');
        $this->assertStringContainsString('sf-mega-media sf-mega-html', $panel);
    }

    public function test_an_empty_column_keeps_its_place(): void
    {
        $this->saveMenu('main', [$this->megaItem('Dresses', '/d', 4, [
            $this->columnItem([$this->menuItem('category', ['target_id' => 2])]), $this->columnItem(), $this->columnItem([$this->menuItem('category', ['target_id' => 3])], 'Third'), $this->columnItem(),
        ])]);

        $html = $this->get('/')->assertOk()->getContent();
        $panel = substr($html, (int) strpos($html, 'class="sf-sub sf-sub-mega"'));

        $this->assertStringContainsString('data-cols="4"', $panel, 'four boxes with two empty still make four columns');
        $this->assertSame(4, substr_count($panel, 'class="sf-mega-col"'));
        $this->assertSame(2, preg_match_all('#<div class="sf-mega-col">\s*</div>#', $panel), 'an empty box prints an empty column');
        $this->assertSame(['Third'], $this->headings((string) strstr($panel, 'id="sf-drawer"', true)), 'in the panel (the drawer comes after it)');
        $this->assertStringNotContainsString('sf-mega-media', $panel, 'no block, no block column');
    }

    public function test_the_css_knows_one_to_four_equal_columns_and_the_block_column_is_untouched(): void
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        foreach ([1, 2, 4] as $n) {
            $this->assertStringContainsString(".sf-mega-cols[data-cols='{$n}']", $css);
        }

        $this->assertStringNotContainsString(".sf-mega-cols[data-cols='5']", $css);
        $this->assertStringNotContainsString(".sf-mega-cols[data-cols='6']", $css);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 7fr) minmax(0, 3fr);', $css, 'the block keeps its width: 3 of 10');
    }

    public function test_the_drawer_shows_columns_as_stacked_sections_headings_only_when_there_are_some_and_no_picture(): void
    {
        $cats = $this->twelveCategories();
        $this->saveMenu('main', [$this->megaItem('Dresses', '/d', 4, [
            $this->columnItem(array_slice($cats, 0, 2)), $this->columnItem(array_slice($cats, 2, 2), 'Shoes'), $this->columnItem(), $this->columnItem(array_slice($cats, 4, 1)),
        ], ['html_block_id' => $this->block()->id])]);

        $html = $this->get('/')->assertOk()->getContent();
        $drawer = substr($html, (int) strpos($html, 'id="sf-drawer"'));

        $this->assertSame(3, substr_count($drawer, 'class="sf-acc-section"'), 'the empty column is not printed in the drawer');
        $this->assertSame(['Shoes'], $this->headings($drawer), 'a heading is a small title only when there is one');
        $this->assertSame(1, substr_count($drawer, 'data-sf-acc-toggle'), 'one toggle for the mega item, none for a section');
        $this->assertStringNotContainsString('sf-mega-html', $drawer);
        $this->assertStringNotContainsString('<img', $drawer);
    }

    /** @return list<string> the headings printed in a piece of markup, in order */
    private function headings(string $html): array
    {
        preg_match_all('#<p class="sf-mega-heading">([^<]*)</p>#', $html, $m);

        return $m[1];
    }
}
