<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuException;
use App\Content\Menus\MenuLocations;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuSeeder;
use App\Content\Menus\MenuTree;
use App\Content\Menus\MenuValidator;
use App\Content\Menus\MenuWriter;
use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Menu\CompiledMenus;
use App\Storefront\Menu\MenuCompileException;
use App\Storefront\Menu\MenuCompiler;
use App\Storefront\Menu\MenuRecompiler;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage D3 (as reshaped by MENU-2): menus as a tool. The structure rules and the link / label rules of the validator, the compiler (what is skipped, what is resolved), the compiled value
 * read back with validation, the rebuild triggers (and their debounce), the markup of the bar / dropdown / mega panel / drawer / footer and the query numbers.
 */
class MenusTest extends TestCase
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
        Storage::fake('public');
        CompiledMenus::forgetWarnings();
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeCategory(4, 'Empty', 'empty');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [1, 2], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
        $this->makeProduct(2, ['slug' => 'boot', 'categories' => [3], 'price' => '20.00', 'stock' => 3, 'name' => 'Boot']);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** @return list<array<string, mixed>> the stored compiled items of one menu */
    private function compiledMenu(string $key): array
    {
        return json_decode((string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY), true)['menus'][$key] ?? [];
    }

    private function fresh(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    private function page(string $slug, string $title, string $status = 'published'): PageModel
    {
        return app(PageWriter::class)->create(['title' => $title, 'slug' => $slug, 'body' => '<p>Text</p>', 'status' => $status]);
    }

    private function link(string $label, string $url, array $extra = []): array
    {
        return $this->menuItem('link', ['label' => $label, 'url' => $url] + $extra);
    }

    // ---- structure and field rules --------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: list<array<string, mixed>>, 1: string}> [tree, the path that must carry the message] */
    public static function brokenTrees(): array
    {
        $link = static fn (string $label = 'A', array $extra = []): array => ['type' => 'link', 'label' => $label, 'url' => '/a'] + $extra;
        $cat = static fn (array $extra = []): array => ['type' => 'category', 'target_id' => 1] + $extra;
        $dropdown = static fn (array $children): array => $link('D', ['submenu' => 'dropdown', 'children' => $children]);
        $mega = static fn (int $n, array $children): array => $link('M', ['submenu' => 'mega', 'mega_columns' => $n, 'children' => $children]);
        $column = static fn (array $children = [], ?string $label = null): array => ['type' => 'column', 'label' => $label, 'children' => $children];
        $image = static fn (): array => ['type' => 'image', 'media_asset_id' => 1];
        $columns = static fn (int $n): array => array_map(fn (): array => ['type' => 'column', 'children' => []], range(1, $n));

        return [
            'depth four' => [[$dropdown([$link('B', ['children' => [$link('C', ['children' => [$link('E')]])]])])], 'items.0.children.0.children.0.children'],
            'a submenu on a child' => [[$dropdown([$link('B', ['submenu' => 'dropdown'])])], 'items.0.children.0.submenu'],
            'an image under a dropdown' => [[$dropdown([$image()])], 'items.0.children.0.type'],
            'a column under a dropdown' => [[$dropdown([$column()])], 'items.0.children.0.type'],
            'a column at the top' => [[$column()], 'items.0.type'],
            'a column under a nested dropdown item' => [[$dropdown([$link('B', ['children' => [$column()]])])], 'items.0.children.0.children.0.type'],
            'a column inside a column' => [[$mega(1, [$column([$column()])])], 'items.0.children.0.children.0.type'],
            'a heading item of the old model' => [[$mega(1, [['type' => 'heading', 'label' => 'H', 'children' => []]])], 'items.0.children.0.type'],
            'a link directly under a mega item' => [[$mega(1, [$link('B')])], 'items.0.children.0.type'],
            'five columns in a mega item' => [[$mega(4, $columns(5))], 'items.0.children'],
            'fewer columns than the mega item says' => [[$mega(3, $columns(2))], 'items.0.children'],
            'more columns than the mega item says' => [[$mega(2, $columns(3))], 'items.0.children'],
            'a mega item without columns' => [[$mega(2, [])], 'items.0.children'],
            'a mega item without a number of columns' => [[$link('M', ['submenu' => 'mega', 'children' => $columns(1)])], 'items.0.mega_columns'],
            'a number of columns of five' => [[$link('M', ['submenu' => 'mega', 'mega_columns' => 5, 'children' => $columns(4)])], 'items.0.mega_columns'],
            'a number of columns of zero' => [[$link('M', ['submenu' => 'mega', 'mega_columns' => 0, 'children' => []])], 'items.0.mega_columns'],
            'a number of columns on a dropdown' => [[$dropdown([]) + ['mega_columns' => 2]], 'items.0.mega_columns'],
            'a number of columns on a plain link' => [[$link('A', ['mega_columns' => 2])], 'items.0.mega_columns'],
            'a column with a link address' => [[$mega(1, [$column() + ['url' => '/x']])], 'items.0.children.0.url'],
            'a column with a target' => [[$mega(1, [$column() + ['target_id' => 1]])], 'items.0.children.0.target_id'],
            'a column with a badge' => [[$mega(1, [$column() + ['badge_text' => 'New']])], 'items.0.children.0.badge_text'],
            'a column heading that is too long' => [[$mega(1, [$column([], str_repeat('h', 61))])], 'items.0.children.0.label'],
            'a column heading with a control character' => [[$mega(1, [$column([], "Hi\nthere")])], 'items.0.children.0.label'],
            'thirteen top-level items' => [array_map(fn (int $i): array => $link("L{$i}"), range(1, 13)), 'items'],
            'sixteen items in a column' => [[$mega(1, [$column(array_map(fn (int $i): array => $link("L{$i}"), range(1, 16)))])], 'items.0.children.0.children'],
            'children without a submenu' => [[$link('A', ['children' => [$link('B')]])], 'items.0.children'],
            'items inside a column item' => [[$mega(1, [$column([$link('B', ['children' => [$link('C')]])])])], 'items.0.children.0.children.0.children'],
            'with_children on a link' => [[$link('A', ['with_children' => true])], 'items.0.with_children'],
            'with_children on a mega item' => [[$cat(['submenu' => 'mega', 'with_children' => true])], 'items.0.with_children'],
            'an unknown type' => [[['type' => 'script']], 'items.0.type'],
            'an unknown key' => [[$link('A', ['onclick' => 'x'])], 'items.0.onclick'],
            'an unknown submenu mode' => [[$link('A', ['submenu' => 'giant'])], 'items.0.submenu'],
            'a category without a target' => [[['type' => 'category']], 'items.0.target_id'],
            'a link without a label' => [[['type' => 'link', 'url' => '/a']], 'items.0.label'],
            'a link without an address' => [[['type' => 'link', 'label' => 'A']], 'items.0.url'],
            'a javascript: link' => [[array_merge($link('A'), ['url' => 'javascript:alert(1)'])], 'items.0.url'],
            'a protocol-relative link' => [[['type' => 'link', 'label' => 'A', 'url' => '//evil.test/x']], 'items.0.url'],
            'a plain http link' => [[['type' => 'link', 'label' => 'A', 'url' => 'http://example.com']], 'items.0.url'],
            'a data: link' => [[['type' => 'link', 'label' => 'A', 'url' => 'data:text/html,x']], 'items.0.url'],
            'a url on a category' => [[$cat(['url' => '/x'])], 'items.0.url'],
            'a label that is too long' => [[$link(str_repeat('a', 121))], 'items.0.label'],
            'a label with a control character' => [[$link("Hi\nthere")], 'items.0.label'],
            'a label with a bidirectional override' => [[$link("Hi\u{202E}x")], 'items.0.label'],
            'a limit over 50' => [[$cat(['children_limit' => 51])], 'items.0.children_limit'],
            'a limit of 0' => [[$cat(['children_limit' => 0])], 'items.0.children_limit'],
            'a badge that is too long' => [[$link('A', ['badge_text' => str_repeat('b', 17)])], 'items.0.badge_text'],
            'a badge colour that is not a colour' => [[$link('A', ['badge_text' => 'New', 'badge_color' => 'red; background:url(x)'])], 'items.0.badge_color'],
            'a badge colour in three digits' => [[$link('A', ['badge_text' => 'New', 'badge_color' => '#f00'])], 'items.0.badge_color'],
            'an HTML block on a dropdown item' => [[$dropdown([])  + ['html_block_id' => 1]], 'items.0.html_block_id'],
            'an HTML block on a nested item' => [[$mega(1, [$column() + ['html_block_id' => 1]])], 'items.0.children.0.html_block_id'],
            'a product without a target' => [[['type' => 'product']], 'items.0.target_id'],
            'thirteen custom badge colours' => [array_map(fn (int $i): array => $link("L{$i}", ['badge_text' => 'x', 'badge_color' => sprintf('#%06x', $i * 1000)]), range(1, 12)) + [12 => $link('L13', ['badge_text' => 'x', 'badge_color' => '#aabbcc'])], 'items'],
        ];
    }

    /** @param  list<array<string, mixed>>  $tree */
    #[DataProvider('brokenTrees')]
    public function test_a_broken_tree_is_refused_with_a_message_on_the_right_path(array $tree, string $path): void
    {
        $validation = MenuValidator::validate($tree);

        $this->assertFalse($validation->passes());
        $this->assertArrayHasKey($path, $validation->errors, 'errors: '.json_encode($validation->errors));
        $this->assertStringNotContainsString('menus.errors.', $validation->errors[$path], 'a sentence, not a key');
    }

    public function test_the_writer_refuses_a_broken_tree_and_changes_nothing(): void
    {
        $this->saveMenu('main', [$this->link('Keep', '/keep')]);
        $before = $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY);

        try {
            $menu = MenuModel::query()->where('key', 'main')->firstOrFail();
            app(MenuWriter::class)->save($menu, 'Main', [$this->link('Bad', 'javascript:alert(1)')]);
            $this->fail('the tree should have been refused');
        } catch (MenuException $exception) {
            $this->assertArrayHasKey('items.0.url', $exception->errors);
        }

        $this->assertSame(['Keep'], array_column(MenuTree::forMenu(MenuModel::query()->where('key', 'main')->value('id')), 'label'));
        $this->assertSame($before, $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY));
    }

    public function test_the_writer_refuses_a_target_that_does_not_exist(): void
    {
        $menu = MenuModel::query()->where('key', 'main')->firstOrFail();

        foreach ([['category', 99], ['page', 99], ['product', 99]] as [$type, $id]) {
            try {
                app(MenuWriter::class)->save($menu, 'Main', [$this->menuItem($type, ['target_id' => $id])]);
                $this->fail('refused');
            } catch (MenuException $exception) {
                $this->assertArrayHasKey('items.0.target_id', $exception->errors);
            }
        }

        try {
            app(MenuWriter::class)->save($menu, 'Main', [$this->menuItem('link', ['label' => 'M', 'url' => '/m', 'submenu' => 'mega', 'html_block_id' => 99, 'mega_columns' => 1, 'children' => [$this->columnItem()]])]);
            $this->fail('refused');
        } catch (MenuException $exception) {
            $this->assertArrayHasKey('items.0.html_block_id', $exception->errors);
        }
    }

    public function test_a_tree_that_follows_the_rules_is_saved_and_read_back_in_its_order(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Picture', 'body' => '<p>x</p>']);
        $this->saveMenu('main', [
            $this->link('First', '/first'),
            $this->megaItem('Women', '/women', 2, [
                $this->columnItem([$this->link('Sale', '/sale', ['badge_text' => 'Sale', 'badge_color' => '#AABBCC']), $this->menuItem('product', ['target_id' => 1])], 'Clothes'),
                $this->columnItem([$this->menuItem('page', ['target_id' => $this->page('terms', 'Terms')->id]), $this->link('Inside', '/inside')]),
            ], ['html_block_id' => $block->id]),
            $this->link('Last', '/last', ['new_tab' => true]),
        ]);

        $tree = MenuTree::forMenu((int) MenuModel::query()->where('key', 'main')->value('id'));

        $this->assertSame(['link', 'link', 'link'], array_column($tree, 'type'));
        $this->assertSame(['First', 'Women', 'Last'], array_column($tree, 'label'));
        $this->assertSame([$block->id, 2], [$tree[1]['html_block_id'], $tree[1]['mega_columns']]);
        $this->assertSame(['column', 'column'], array_column($tree[1]['children'], 'type'));
        $this->assertSame(['Clothes', null], array_column($tree[1]['children'], 'label'));
        $this->assertSame(['link', 'product'], array_column($tree[1]['children'][0]['children'], 'type'));
        $this->assertSame(['page', 'link'], array_column($tree[1]['children'][1]['children'], 'type'));
        $this->assertSame(['Sale', '#aabbcc'], [$tree[1]['children'][0]['children'][0]['badge_text'], $tree[1]['children'][0]['children'][0]['badge_color']], 'the colour is stored lower-case');
        $this->assertTrue($tree[2]['new_tab']);
    }

    public function test_an_invalid_item_writes_nothing_all_or_nothing(): void
    {
        $this->saveMenu('main', [$this->link('Keep', '/keep')]);
        $menu = MenuModel::query()->where('key', 'main')->firstOrFail();
        $rows = DB::table('menu_items')->count();

        try {
            // the first two items are fine, the third is not: not even the first two are written
            app(MenuWriter::class)->save($menu, 'Renamed', [$this->link('New 1', '/n1'), $this->link('New 2', '/n2'), $this->link('Bad', 'javascript:alert(1)')]);
            $this->fail('refused');
        } catch (MenuException $exception) {
            $this->assertSame(['items.2.url'], array_keys($exception->errors), 'the error is keyed by the index of the faulty item');
        }

        $this->assertSame($rows, DB::table('menu_items')->count());
        $this->assertSame(['Keep'], array_column(MenuTree::forMenu($menu->id), 'label'));
        $this->assertNotSame('Renamed', $menu->fresh()->name);
    }

    // ---- the compiler ------------------------------------------------------------------------------------------------------------------------

    public function test_a_category_that_is_not_shown_is_skipped_with_its_whole_subtree(): void
    {
        $this->saveMenu('main', [
            $this->menuItem('category', ['target_id' => 4, 'submenu' => 'dropdown', 'children' => [$this->link('Inside', '/inside')]]),
            $this->menuItem('category', ['target_id' => 3]),
        ]);

        $compiled = $this->compiledMenu('main');

        $this->assertSame(['Shoes'], array_column($compiled, 'label'), 'Empty has no visible product: it and its child link are gone');
        $this->assertStringNotContainsString('Inside', (string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY));
    }

    public function test_an_unpublished_page_is_skipped_and_a_published_one_is_resolved(): void
    {
        $published = $this->page('terms', 'Terms');
        $draft = $this->page('draft-page', 'Draft page', 'draft');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $published->id]), $this->menuItem('page', ['target_id' => $draft->id])]);

        $compiled = $this->compiledMenu('footer');

        $this->assertSame(['Terms'], array_column($compiled, 'label'));
        $this->assertSame('/info/terms', $compiled[0]['url']);
    }

    public function test_a_deleted_target_is_skipped_not_a_broken_menu(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3]), $this->link('Stay', '/stay')]);
        DB::table('catalog_product_categories')->where('category_id', 3)->delete();
        DB::table('catalog_categories')->where('id', 3)->delete();

        app(MenuRecompiler::class)->now();

        $this->assertSame(['Stay'], array_column($this->compiledMenu('main'), 'label'));
    }

    public function test_an_empty_label_shows_the_targets_own_name_and_a_label_wins(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 1]), $this->menuItem('category', ['target_id' => 3, 'label' => 'Footwear'])]);

        $this->assertSame(['Clothing', 'Footwear'], array_column($this->compiledMenu('main'), 'label'));
    }

    public function test_a_dropdown_with_nothing_left_becomes_a_plain_link(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3, 'submenu' => 'dropdown', 'children' => [$this->menuItem('category', ['target_id' => 4])]])]);

        $item = $this->compiledMenu('main')[0];

        $this->assertSame('none', $item['submenu'], 'Empty has no visible product, so the dropdown has nothing to show');
        $this->assertSame([], $item['children']);
    }

    public function test_a_product_item_links_to_the_product_and_is_skipped_when_it_is_not_visible(): void
    {
        $this->saveMenu('main', [$this->menuItem('product', ['target_id' => 1]), $this->menuItem('product', ['target_id' => 2, 'label' => 'The boot'])]);

        $this->assertSame(['Summer dress', 'The boot'], array_column($this->compiledMenu('main'), 'label'));
        $this->assertSame('/product/dress', $this->compiledMenu('main')[0]['url']);

        ProductModel::query()->findOrFail(1)->update(['status' => 'draft']);
        app(MenuRecompiler::class)->flush();

        $this->assertSame(['The boot'], array_column($this->compiledMenu('main'), 'label'), 'a hidden product is skipped by the rebuild that its change asked for');

        ProductModel::query()->findOrFail(1)->update(['status' => 'active']);
        app(MenuRecompiler::class)->flush();
        $this->assertSame(['Summer dress', 'The boot'], array_column($this->compiledMenu('main'), 'label'));
    }

    public function test_a_mega_item_compiles_to_its_columns_and_the_html_block_rides_it(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Boys', 'body' => '<figure><img src="/storage/html-blocks/a.jpg" alt="A boot" width="300" height="200"></figure><script>alert(1)</script>']);
        $this->saveMenu('main', [$this->megaItem('Boys', '/boys', 3, [
            $this->columnItem([$this->link('Jackets', '/jackets')], 'Clothes'),
            $this->columnItem(),
            $this->columnItem([$this->link('Boots', '/boots'), $this->menuItem('category', ['target_id' => 4])]),
        ], ['html_block_id' => $block->id])]);

        $mega = $this->compiledMenu('main')[0];

        $this->assertSame('mega', $mega['submenu']);
        $this->assertSame(['column', 'column', 'column'], array_column($mega['children'], 'type'), 'an EMPTY column keeps its place');
        $this->assertSame(['Clothes', null, null], array_column($mega['children'], 'label'), 'the heading is optional');
        $this->assertSame(['Jackets'], array_column($mega['children'][0]['children'], 'label'));
        $this->assertSame([], $mega['children'][1]['children']);
        $this->assertSame(['Boots'], array_column($mega['children'][2]['children'], 'label'), 'a hidden category inside a column is skipped, the column stays');
        $this->assertStringContainsString('<img loading="lazy" decoding="async" src="/storage/html-blocks/a.jpg" alt="A boot" width="300" height="200">', $mega['html']);
        $this->assertStringNotContainsString('script', $mega['html']);
    }

    public function test_a_mega_item_whose_columns_are_all_empty_is_a_plain_link(): void
    {
        $this->saveMenu('main', [$this->megaItem('Boys', '/boys', 3, [$this->columnItem([$this->menuItem('category', ['target_id' => 4])], 'Hidden')])]);

        $item = $this->compiledMenu('main')[0];

        $this->assertSame(['none', []], [$item['submenu'], $item['children']]);
        $this->assertNull($item['html']);
    }

    public function test_the_html_block_is_part_of_the_compiled_menu_and_an_edit_follows(): void
    {
        $block = HtmlBlockModel::create(['title' => 'B', 'body' => '<p>One</p>']);
        $this->saveMenu('main', [$this->megaItem('Boys', '/boys', 1, [$this->columnItem([$this->link('Jackets', '/jackets')], 'Clothes')], ['html_block_id' => $block->id])]);
        $this->assertStringContainsString('One', $this->get('/')->assertOk()->getContent());

        $block->update(['body' => '<p>Two</p>']);
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="sf-mega-media sf-mega-html"><p>Two</p></div>', $html);
        $this->assertStringNotContainsString('One', $html);
    }

    public function test_a_badge_is_a_class_never_a_style_attribute(): void
    {
        $this->saveMenu('main', [
            $this->link('Sale', '/sale', ['badge_text' => 'Sale', 'badge_color' => 'orange']),
            $this->link('New', '/new', ['badge_text' => 'New', 'badge_color' => '#1A2B3C']),
            $this->link('Plain badge', '/p', ['badge_text' => 'Hot']),
        ]);
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="sf-badge sf-badge-orange">Sale</span>', $html);
        $this->assertStringContainsString('<span class="sf-badge sf-badge-c-1a2b3c">New</span>', $html);
        $this->assertStringContainsString('<span class="sf-badge">Hot</span>', $html);
        $this->assertDoesNotMatchRegularExpression('/<[^>]*\sstyle=/i', $html, 'no inline style on the page');

        // the custom colour lives in the token stylesheet as a class
        $hash = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true)['hash'];
        $css = Storage::disk('public')->get('appearance/tokens-'.$hash.'.css');
        $this->assertStringContainsString('.sf-badge-c-1a2b3c { --sf-badge-bg: #1a2b3c; --sf-badge-fg: #ffffff; }', (string) $css);

        // and goes again when no item uses it
        $this->saveMenu('main', [$this->link('Sale', '/sale')]);
        $compiled = $this->settings()->get(AppearanceSettings::COMPILED_KEY);
        $this->assertTrue($compiled === null || ! str_contains((string) Storage::disk('public')->get('appearance/tokens-'.json_decode($compiled, true)['hash'].'.css'), 'sf-badge-c-1a2b3c'));
    }

    public function test_a_compiled_value_over_the_size_limit_refuses_the_save_and_keeps_the_old_one(): void
    {
        $this->saveMenu('main', [$this->link('Old', '/old')]);
        $before = $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY);
        $this->app->bind(MenuCompiler::class, fn () => new class($this->settings(), app(\App\Storefront\Url\StorefrontUrls::class), app(\App\Storefront\Visibility\StorefrontVisibility::class), app(MenuLocations::class)) extends MenuCompiler {
            public const MAX_BYTES = 40;
        });
        $this->app->forgetInstance(MenuRecompiler::class);

        try {
            $menu = MenuModel::query()->where('key', 'main')->firstOrFail();
            app(MenuWriter::class)->save($menu, 'Main', [$this->link('A much longer item than the new tiny limit allows', '/long')]);
            $this->fail('the save should have been refused');
        } catch (MenuCompileException $exception) {
            $this->assertStringContainsString('too large', $exception->getMessage());
        }

        $this->assertSame($before, $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY));
        $this->assertSame(['Old'], array_column(MenuTree::forMenu((int) MenuModel::query()->where('key', 'main')->value('id')), 'label'), 'the rows were rolled back too');
    }

    // ---- reading the compiled value ------------------------------------------------------------------------------------------------------------

    private function putCompiled(array $menus): void
    {
        $this->settings()->set(AppearanceSettings::MENUS_COMPILED_KEY, json_encode(['menus' => $menus, 'locations' => ['header' => 'main', 'drawer' => null, 'footer' => 'footer']]));
        $this->fresh();
    }

    private function goodLink(string $label = 'Good', string $url = '/good'): array
    {
        return ['type' => 'link', 'label' => $label, 'url' => $url, 'new_tab' => false, 'submenu' => 'none', 'badge' => null, 'html' => null, 'children' => []];
    }

    public function test_a_hand_edited_menu_with_a_javascript_link_renders_nothing_and_logs_once(): void
    {
        Log::spy();
        $this->putCompiled(['main' => [$this->goodLink('Fine'), $this->goodLink('Evil', 'javascript:alert(1)')], 'footer' => [$this->goodLink('Footer ok', '/f')]]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->fresh();
        $this->get('/')->assertOk();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('Evil', $html);
        $this->assertStringNotContainsString('Fine', $html, 'the whole menu is dropped, not just the bad item');
        $this->assertStringContainsString('Footer ok', $html, 'another menu is not affected');
        Log::shouldHaveReceived('warning')->once();
    }

    #[DataProvider('invalidCompiledNodes')]
    public function test_other_hand_edited_shapes_render_an_empty_menu(array $node): void
    {
        $this->putCompiled(['main' => [$this->goodLink('Fine'), $node]]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('sf-bar-link', $html);
        $this->assertStringNotContainsString('Fine', $html);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidCompiledNodes(): array
    {
        $link = ['type' => 'link', 'label' => 'X', 'url' => '/x', 'new_tab' => false, 'submenu' => 'none', 'badge' => null, 'html' => null, 'children' => []];

        return [
            'an unknown type' => [['type' => 'iframe', 'src' => 'x']],
            'a protocol-relative url' => [['url' => '//evil.test'] + $link],
            'a label that is not a string' => [['label' => ['x']] + $link],
            'a label with a control character' => [['label' => "A\u{0007}B"] + $link],
            'an unknown submenu mode' => [['submenu' => 'huge'] + $link],
            'new_tab that is not a boolean' => [['new_tab' => 'yes'] + $link],
            'an item of the old model (a heading)' => [['type' => 'heading', 'label' => 'H', 'children' => []]],
            'a column heading that is too long' => [['type' => 'column', 'label' => str_repeat('h', 61), 'children' => []]],
            'a column with a link that is not valid' => [['type' => 'column', 'label' => null, 'children' => [['url' => 'javascript:alert(1)'] + $link]]],
            'a badge with a style in its colour' => [['badge' => ['text' => 'New', 'color' => 'red;x:y']] + $link],
            'a badge text that is too long' => [['badge' => ['text' => str_repeat('x', 17), 'color' => null]] + $link],
            'a block that is not a string' => [['submenu' => 'mega', 'html' => ['x']] + $link],
            'a tree that is far too deep' => [['children' => [['children' => [['children' => [['children' => [['children' => [['children' => [$link]] + $link]] + $link]] + $link]] + $link]] + $link]] + $link],
        ];
    }

    public function test_a_valid_stored_menu_is_rebuilt_from_known_keys_only(): void
    {
        $good = $this->goodLink('Fine');
        $good['onclick'] = 'x';
        $read = CompiledMenus::read(['main' => [$good]]);

        $this->assertSame(['type', 'label', 'url', 'new_tab', 'submenu', 'badge', 'html', 'children'], array_keys($read['main'][0]), 'nothing unvalidated is passed on');
    }

    public function test_the_html_block_is_cleaned_again_when_the_compiled_menu_is_read(): void
    {
        $evil = ['submenu' => 'mega', 'html' => '<p style="x" onclick="y">Hi</p><script>alert(1)</script><img src="javascript:alert(1)"><img src="/storage/a.png" onerror="x">', 'children' => [['type' => 'column', 'label' => 'H', 'children' => [$this->goodLink('L', '/l')]]]] + $this->goodLink('M', '/m');
        $read = CompiledMenus::read(['main' => [$evil]]);

        $this->assertSame('<p>Hi</p><img loading="lazy" decoding="async" src="/storage/a.png" alt="">', $read['main'][0]['html']);
    }

    // ---- the rebuild triggers ----------------------------------------------------------------------------------------------------------------------

    public function test_unpublishing_and_publishing_a_page_changes_the_footer_without_a_manual_save(): void
    {
        $page = $this->page('terms', 'Terms');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);
        $this->assertStringContainsString('href="/info/terms"', $this->get('/')->assertOk()->getContent());

        app(PageWriter::class)->update($page, ['status' => 'draft']);
        $this->fresh();
        $this->assertStringNotContainsString('href="/info/terms"', $this->get('/')->assertOk()->getContent(), 'unpublished: gone');

        app(PageWriter::class)->update($page->refresh(), ['status' => 'published']);
        $this->fresh();
        $this->assertStringContainsString('href="/info/terms"', $this->get('/')->assertOk()->getContent(), 'published again: back');
    }

    public function test_a_renamed_page_and_a_new_slug_are_followed(): void
    {
        $page = $this->page('terms', 'Terms');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);

        app(PageWriter::class)->update($page, ['title' => 'Terms of sale', 'slug' => 'terms-of-sale']);
        $this->fresh();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<a href="/info/terms-of-sale">Terms of sale</a>', $html);
    }

    public function test_hiding_the_last_product_of_a_category_removes_it_from_the_menu_without_a_manual_save(): void
    {
        $this->mainMenuOfTopCategories();
        $this->assertStringContainsString('href="/product-category/shoes"', $this->get('/')->assertOk()->getContent());

        ProductModel::query()->findOrFail(2)->update(['status' => 'draft']);
        $this->fresh();

        $this->assertStringNotContainsString('href="/product-category/shoes"', $this->get('/')->assertOk()->getContent(), 'the category is not shown any more, so neither is its menu item');

        ProductModel::query()->findOrFail(2)->update(['status' => 'active']);
        $this->fresh();
        $this->assertStringContainsString('href="/product-category/shoes"', $this->get('/')->assertOk()->getContent());
    }

    public function test_renaming_and_creating_a_category_follow_the_menu(): void
    {
        $this->mainMenuOfTopCategories();

        CategoryModel::query()->findOrFail(3)->update(['name' => 'Boots']);
        $this->fresh();
        $this->assertStringContainsString('>Boots</a>', $this->get('/')->assertOk()->getContent());

        CategoryModel::query()->findOrFail(3)->delete();
        $this->fresh();
        $this->assertStringNotContainsString('>Boots</a>', $this->get('/')->assertOk()->getContent());
    }

    public function test_several_changes_in_one_request_rebuild_once(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $page = $this->page('a-page', 'A page');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3])]);
        $runs = $recompiler->runs;

        app(PageWriter::class)->update($page, ['title' => 'Another title']);
        CategoryModel::query()->findOrFail(3)->update(['name' => 'Footwear']);
        CategoryModel::query()->findOrFail(1)->update(['name' => 'Wear']);
        $this->assertTrue($recompiler->hasPending());
        $this->assertSame($runs, $recompiler->runs, 'nothing ran yet: the rebuild waits for the end of the request');

        $recompiler->flush();
        $recompiler->flush();

        $this->assertSame($runs + 1, $recompiler->runs);
        $this->assertFalse($recompiler->hasPending());
    }

    public function test_a_shop_whose_menus_hold_no_category_pays_nothing_when_a_product_changes(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $this->saveMenu('main', [$this->link('Only a link', '/x')]);

        ProductModel::query()->findOrFail(1)->update(['status' => 'draft']);
        CategoryModel::query()->findOrFail(3)->update(['name' => 'Renamed']);

        $this->assertFalse($recompiler->hasPending(), 'no menu item is a category: nothing could change');
    }

    public function test_a_product_save_that_changes_nothing_visible_asks_for_no_rebuild(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3])]);

        ProductModel::query()->findOrFail(1)->update(['name' => 'Renamed dress']);

        $this->assertFalse($recompiler->hasPending(), 'a name is not shown by a category item, and product 1 is in no menu');
    }

    public function test_a_product_that_is_a_menu_item_follows_its_name_and_slug(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $this->saveMenu('main', [$this->menuItem('product', ['target_id' => 2])]);

        ProductModel::query()->findOrFail(2)->update(['name' => 'Winter boot', 'slug' => 'winter-boot']);

        $this->assertTrue($recompiler->hasPending());
        $recompiler->flush();
        $this->assertSame(['Winter boot'], array_column($this->compiledMenu('main'), 'label'));
        $this->assertSame('/product/winter-boot', $this->compiledMenu('main')[0]['url']);
    }

    public function test_the_pending_rebuild_runs_after_a_queue_job(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $recompiler->request();
        $runs = $recompiler->runs;

        event(new JobProcessed('sync', \Mockery::mock(\Illuminate\Contracts\Queue\Job::class)));

        $this->assertSame($runs + 1, $recompiler->runs);
    }

    // ---- query numbers ---------------------------------------------------------------------------------------------------------------------------------

    /** @return array{0: list<string>, 1: list<string>} the queries of a COLD page and the ones that touch menus */
    private function queries(string $url): array
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $route->flushController();
        }

        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
        $all = [];
        DB::listen(function ($query) use (&$all): void {
            $all[] = $query->sql.' | '.json_encode($query->bindings);
        });

        $this->get($url)->assertOk();

        return [$all, array_values(array_filter($all, fn (string $q): bool => str_contains($q, 'menus') || str_contains($q, 'menu_items')))];
    }

    public function test_menus_cost_no_query_of_their_own_on_any_storefront_page(): void
    {
        $this->page('terms', 'Terms');
        app(MenuRecompiler::class)->flush(); // the page above was made by a writer: its rebuild belongs to the end of THAT request
        $url = fn (string $path): string => $path;
        $pages = ['/', '/product-category/clothing', '/product/dress', '/cart', '/checkout', '/info/terms'];

        $empty = [];

        foreach ($pages as $path) {
            $empty[$path] = $this->queries($url($path))[0];
        }


        $this->mainMenuOfTopCategories();
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => PageModel::query()->value('id')])]);

        foreach ($pages as $path) {
            [$queries, $menuQueries] = $this->queries($url($path));

            $this->assertSame(count($empty[$path]), count($queries), "{$path}: a full menu does not add a query. Extra: ".json_encode(array_values(array_diff($queries, $empty[$path]))));
            $this->assertCount(1, $menuQueries, "{$path}: exactly the one appearance read names menus.compiled, no table of the menus is read");
            $this->assertStringContainsString('site_settings', $menuQueries[0]);
        }
    }

    // ---- the markup --------------------------------------------------------------------------------------------------------------------------------------

    private function megaMenu(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Boots picture', 'body' => '<figure><a href="/promo"><img src="/storage/html-blocks/boot.jpg" alt="A boot" width="900" height="675"></a><figcaption>Promo</figcaption></figure>']);
        $this->saveMenu('main', [
            $this->link('Plain', '/plain'),
            $this->menuItem('category', ['target_id' => 1, 'submenu' => 'dropdown', 'children' => [
                $this->menuItem('category', ['target_id' => 2, 'children' => [$this->link('Party dresses', '/party', ['badge_text' => 'Hot', 'badge_color' => 'red'])]]),
            ]]),
            $this->megaItem('Footwear', '/footwear', 2, [
                $this->columnItem([$this->link('Winter', '/winter'), $this->link('Summer', '/summer', ['new_tab' => true])], 'Boots'),
                $this->columnItem([$this->link('Flat', '/flat')], 'Sandals'),
            ], ['html_block_id' => $block->id]),
        ]);
    }

    public function test_a_dropdown_item_is_a_link_plus_a_separate_disclosure_button(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();

        // The parent is a real link...
        $this->assertMatchesRegularExpression('#<a class="sf-bar-link" href="/product-category/clothing">Clothing</a>#', $html);
        // ...and a separate button owns the open / close state.
        $this->assertMatchesRegularExpression('#<button class="sf-bar-chevron" type="button" aria-expanded="false" aria-controls="(sf-sub-[a-z0-9-]+)" data-sf-sub-toggle>#', $html);
        preg_match('#<button class="sf-bar-chevron" type="button" aria-expanded="false" aria-controls="([^"]+)"#', $html, $m);
        $this->assertSame(1, substr_count($html, 'id="'.$m[1].'"'), 'aria-controls points at an existing panel');
        $this->assertSame(0, preg_match('#<button[^>]*>\s*Clothing#', $html), 'the label is never the button');
        $this->assertStringContainsString('class="sf-sub sf-sub-dropdown"', $html);
        $this->assertStringContainsString('<a class="sf-menu-link" href="/product-category/clothing/dresses">Dresses', $html);
    }

    public function test_a_dropdowns_second_level_is_a_nested_list_with_its_badge(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a class="sf-menu-link" href="/product-category/clothing/dresses">Dresses</a>\s*<ul class="sf-menu">\s*<li class="sf-menu-item">\s*<a class="sf-menu-link" href="/party">Party dresses<span class="sf-badge sf-badge-red">Hot</span></a>#', $html);
        $this->assertStringContainsString('.sf-sub-dropdown .sf-menu .sf-menu', (string) file_get_contents(resource_path('css/storefront.css')), 'the second level is a flyout');
    }

    public function test_the_mega_panel_has_its_columns_from_the_structure_and_the_block_on_the_right(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="sf-sub sf-sub-mega"', $html);
        $this->assertStringContainsString('<div class="sf-mega-cols" data-cols="2">', $html);
        $this->assertSame(2, substr_count($html, 'class="sf-mega-col"'));
        $this->assertStringContainsString('<p class="sf-mega-heading">Boots</p>', $html);
        $this->assertStringContainsString('<p class="sf-mega-heading">Sandals</p>', $html);
        $this->assertStringContainsString('<a class="sf-menu-link" href="/summer" target="_blank" rel="noopener">Summer</a>', $html);
        $this->assertMatchesRegularExpression('#<div class="sf-mega-media sf-mega-html"><figure><a href="/promo" rel="noopener nofollow"><img loading="lazy" decoding="async" src="/storage/html-blocks/boot.jpg" alt="A boot" width="900" height="675"></a><figcaption>Promo</figcaption></figure></div>#', $html);
        $this->assertGreaterThan(strpos($html, 'class="sf-mega-cols"'), strpos($html, 'class="sf-mega-media'), 'the block comes after the columns: it sits on the right');
        $this->assertNoActiveContent(preg_replace('#<form[ >].*?</form>#s', '', $html), 'mega menu');
    }

    public function test_the_drawer_shows_a_mega_item_as_stacked_sections_without_a_second_accordion_or_the_block(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();
        $drawer = substr($html, (int) strpos($html, 'id="sf-drawer"'));

        $this->assertStringContainsString('class="sf-acc"', $drawer);
        $this->assertStringContainsString('<a class="sf-acc-link" href="/product-category/clothing">Clothing</a>', $drawer);
        $this->assertSame(2, substr_count($drawer, 'class="sf-acc-section"'), 'one section per column, one under another');
        $this->assertStringContainsString('<p class="sf-mega-heading">Boots</p>', $drawer);
        $this->assertStringContainsString('>Winter</a>', $drawer);
        $this->assertSame(2, substr_count($drawer, 'data-sf-acc-toggle'), 'only the two top-level items with a submenu have a toggle: a section has none');
        $this->assertStringNotContainsString('sf-mega-html', $drawer);
        $this->assertStringNotContainsString('Promo', $drawer);
    }

    public function test_without_the_script_nothing_is_hidden_and_the_list_wraps_in_its_own_column(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        $this->assertSame(3, preg_match_all('/<li class="sf-bar-item[^"]*" data-sf-bar-item>/', $html), 'every top-level item is in the page');
        $this->assertSame(1, preg_match_all('/<li[^>]* hidden>/', $html), 'only the (empty) More item is hidden');
        $this->assertMatchesRegularExpression('/\.sf-bar \{[^}]*flex-wrap: wrap;/', $css, 'wraps by default (no script)');
        $this->assertMatchesRegularExpression('/html\.js \.sf-bar \{\s*flex-wrap: nowrap;/', $css, 'one row only once the script can fit it');
        $this->assertMatchesRegularExpression('/\.sf-hdr-menu-links \{\s*flex: 1 1 auto;\s*min-width: 0;/', $css, 'the bar lives inside its own column');
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);', $css, 'the centre column stays auto, the sides shrink (S3-V2)');
        $this->assertMatchesRegularExpression('/html:not\(\.js\) \.sf-bar-item:hover > \.sf-sub/', $css, 'a panel opens on hover and focus without the script');
    }

    public function test_the_footer_renders_the_footer_menu_only(): void
    {
        $page = $this->page('terms', 'Terms');
        $this->page('privacy', 'Privacy');
        DB::table('pages')->update(['in_footer' => 1]);
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id]), $this->link('Our blog', 'https://blog.example.com/', ['new_tab' => true]), $this->menuItem('category', ['target_id' => 3])]);

        $html = $this->get('/')->assertOk()->getContent();
        $footer = substr($html, (int) strpos($html, '<footer'));

        $this->assertStringContainsString('<li><a href="/info/terms">Terms</a></li>', $footer);
        $this->assertStringContainsString('<li><a href="https://blog.example.com/" target="_blank" rel="noopener">Our blog</a></li>', $footer);
        $this->assertStringContainsString('<li><a href="/product-category/shoes">Shoes</a></li>', $footer);
        $this->assertStringNotContainsString('Privacy', $footer, 'the old in_footer flag no longer puts a page in the footer');
    }

    public function test_the_seed_puts_the_old_footer_pages_in_the_footer_menu_in_the_same_order_and_is_idempotent(): void
    {
        $first = $this->page('terms', 'Terms');
        $second = $this->page('privacy', 'Privacy');
        $third = $this->page('delivery', 'Delivery');
        $this->page('hidden', 'Not in footer');
        DB::table('pages')->where('id', $first->id)->update(['in_footer' => 1, 'footer_order' => 2]);
        DB::table('pages')->where('id', $second->id)->update(['in_footer' => 1, 'footer_order' => 1]);
        DB::table('pages')->where('id', $third->id)->update(['in_footer' => 1, 'footer_order' => 1]);
        MenuModel::query()->whereIn('key', ['main', 'footer'])->delete();

        MenuSeeder::run();
        app(MenuRecompiler::class)->now();

        $this->assertSame(['Delivery', 'Privacy', 'Terms'], array_column($this->compiledMenu('footer'), 'label'), 'footer_order, then title: the order the footer had');

        // The merchant changes the main menu; a second run leaves everything alone.
        $this->saveMenu('main', [$this->link('Mine', '/mine')]);
        MenuSeeder::run();

        $this->assertSame(['Mine'], array_column($this->compiledMenu('main'), 'label'), 'the own main menu of the merchant is left alone');
        $this->assertSame(3, DB::table('menu_items')->where('menu_id', MenuModel::query()->where('key', 'footer')->value('id'))->count(), 'and the footer got nothing twice');
        $this->assertSame(2, MenuModel::query()->count());
    }

    public function test_the_seed_builds_main_from_the_top_level_categories_in_their_order(): void
    {
        MenuModel::query()->whereIn('key', ['main', 'footer'])->delete();

        MenuSeeder::run();
        app(MenuRecompiler::class)->now();

        $main = $this->compiledMenu('main');

        $this->assertSame(['Clothing', 'Shoes'], array_column($main, 'label'), 'Empty has no product: not shown, but still in the menu for the merchant to see');
        $this->assertSame(['dropdown', 'none'], array_column($main, 'submenu'));
        $this->assertSame(['Dresses'], array_column($main[0]['children'], 'label'));
        $this->assertSame(4, DB::table('menu_items')->where('menu_id', MenuModel::query()->where('key', 'main')->value('id'))->count(), 'Clothing, its sub-category Dresses as an explicit item, Shoes, Empty');
    }

    public function test_the_hamburger_is_named_menu_not_categories(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-sf-menu-toggle[^>]*>.*?<span class="sf-sr-only">Menu</span>#s', $html);
        $this->assertStringNotContainsString('sf-sr-only">Categories<', $html);
    }

    public function test_the_header_shows_the_menu_of_its_location_and_nothing_without_one(): void
    {
        $this->saveMenu('main', [$this->link('From main', '/m')]);
        $footerMenu = MenuModel::query()->where('key', 'footer')->firstOrFail();
        app(MenuWriter::class)->save($footerMenu, 'Footer', [$this->link('From footer', '/f')], ['footer']);
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('>From main</a>', $html, 'the header location is the main menu');
        $this->assertStringNotContainsString('sf-bar-link" href="/f"', $html);

        // the header location is moved to the other menu
        $moved = app(MenuWriter::class)->save($footerMenu, 'Footer', [$this->link('From footer', '/f')], ['header', 'footer']);
        $this->assertSame(['header' => 'Main menu'], $moved, 'the note names the menu it came from');
        $this->fresh();
        $this->assertStringContainsString('sf-bar-link" href="/f">From footer</a>', $this->get('/')->assertOk()->getContent());

        // no menu in the header location: nothing is printed, and the category tree does not take its place
        app(MenuLocations::class)->store(['header' => null, 'drawer' => null, 'footer' => $footerMenu->id]);
        app(MenuRecompiler::class)->now();
        $this->fresh();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-sf-menubar', $html);
        $this->assertStringNotContainsString('sf-bar-link', $html, 'the category tree does not take the place of a menu');
    }

    public function test_the_drawer_shows_its_own_menu_or_else_the_headers(): void
    {
        $this->saveMenu('main', [$this->link('Header link', '/h')]);
        $footerMenu = MenuModel::query()->where('key', 'footer')->firstOrFail();
        $drawer = fn (): string => substr($html = $this->get('/')->assertOk()->getContent(), (int) strpos($html, 'id="sf-drawer"'));

        $this->assertStringContainsString('Header link', $drawer(), 'no drawer location: the header menu');

        app(MenuWriter::class)->save($footerMenu, 'Footer', [$this->link('Drawer link', '/d')], ['drawer', 'footer']);
        $this->fresh();
        $html = $this->get('/')->assertOk()->getContent();
        $drawerHtml = substr($html, (int) strpos($html, 'id="sf-drawer"'));

        $this->assertStringContainsString('Drawer link', $drawerHtml);
        $this->assertStringNotContainsString('Header link', $drawerHtml);
        $this->assertStringContainsString('Header link', substr($html, 0, (int) strpos($html, 'id="sf-drawer"')), 'the bar still shows the header menu');
    }

    public function test_a_menu_that_holds_a_location_is_not_deleted(): void
    {
        $menu = app(MenuWriter::class)->create('extra', 'Extra');
        app(MenuLocations::class)->assign($menu->id, ['drawer']);

        try {
            app(MenuWriter::class)->delete($menu);
            $this->fail('refused');
        } catch (MenuException $exception) {
            $this->assertStringContainsString('Phone', $exception->getMessage());
        }

        app(MenuLocations::class)->release($menu->id);
        app(MenuWriter::class)->delete($menu);
        $this->assertNull(MenuModel::query()->where('key', 'extra')->first());
    }
}
