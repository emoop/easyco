<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuEditorData;
use App\Content\Menus\MenuFlat;
use App\Content\Menus\MenuLocations;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuTree;
use App\Content\PageWriter;
use App\Filament\NavigationGroup;
use App\Filament\Pages\Design\Menus;
use App\Filament\Pages\Settings\AppearanceSettings as AppearancePage;
use App\Filament\Pages\Design\DesignScreen;
use App\Filament\Pages\Settings\StorefrontSettings;
use App\Filament\Resources\HtmlBlockResource;
use App\Filament\Resources\PageResource;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * MENU-2 (part 2): the editor page «Дизайн -> Менюта». Who may use it, where it sits in the navigation, what it hands the browser, and the ONE save: a flat list with depths
 * becomes a tree, is validated and written all-or-nothing, the errors come back by item index, a location moves from the menu that had it. The drag and the cards are the
 * browser's (tests/Browser/menu-editor.mjs; the rules of a move under node in MenuEditorCoreJsTest).
 */
class MenusPageTest extends TestCase
{
    use BuildsMenus;
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeCategory(4, 'Empty', 'empty');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [1, 2], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
        $this->makeProduct(2, ['slug' => 'boot', 'categories' => [3], 'price' => '20.00', 'stock' => 3, 'name' => 'Winter boot']);
    }

    private function menu(string $key = 'main'): MenuModel
    {
        return MenuModel::query()->where('key', $key)->firstOrFail();
    }

    private function row(string $type, int $depth, array $extra = []): array
    {
        return array_merge(['label' => null, 'url' => null, 'target_id' => null, 'submenu' => 'none', 'html_block_id' => null, 'badge_text' => null, 'badge_color' => null, 'new_tab' => false, 'visible' => true], $extra, ['type' => $type, 'depth' => $depth]);
    }

    private function link(string $label, int $depth, array $extra = []): array
    {
        return $this->row('link', $depth, array_merge(['label' => $label, 'url' => '/'.strtolower(str_replace(' ', '-', $label))], $extra));
    }

    /** The page as the staff member, with the call surface the browser uses. */
    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(Menus::class);
    }

    // ---- access and navigation ---------------------------------------------------------------------------------------------------------

    public function test_the_content_roles_reach_the_page_and_a_role_without_the_permission_does_not(): void
    {
        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);

            $this->get(Menus::getUrl())->assertOk();
        }

        foreach ([fn () => $this->actingAsStaff('Product Entry'), fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW])] as $actAs) {
            $actAs();

            $this->get(Menus::getUrl())->assertForbidden();
            $this->assertFalse(Menus::canAccess());
            Livewire::test(Menus::class)->assertForbidden();
        }
    }

    public function test_every_call_checks_the_permission_again(): void
    {
        $page = $this->page()->instance();
        $this->actingAsStaff('Product Entry'); // the same component object, a member of staff who may not

        foreach (['loadMenu' => [1], 'createMenu' => ['X'], 'saveMenu' => [1, 'n', [], []], 'deleteMenu' => [1], 'searchProducts' => ['a']] as $method => $arguments) {
            try {
                $page->{$method}(...$arguments);
                $this->fail($method.' must be forbidden');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode(), $method);
            }
        }
    }

    public function test_the_design_group_holds_appearance_header_menus_blocks_storefront_in_that_order(): void
    {
        $order = [DesignScreen::class, AppearancePage::class, Menus::class, HtmlBlockResource::class, StorefrontSettings::class];
        $sorts = [];

        foreach ($order as $class) {
            $this->assertSame(NavigationGroup::DESIGN, $class::getNavigationGroup(), $class);
            $sorts[] = $class::getNavigationSort();
        }

        $sorted = $sorts;
        sort($sorted);
        $this->assertSame($sorted, $sorts, 'the sorts ascend in the order of the brief');
        $this->assertSame(5, count(array_unique($sorts)));
        $this->assertSame('Design', NavigationGroup::DESIGN->getLabel());
        app()->setLocale('bg');
        $this->assertSame('Дизайн', NavigationGroup::DESIGN->getLabel());
        $cases = NavigationGroup::cases();
        $this->assertSame([NavigationGroup::CONTENT, NavigationGroup::DESIGN, NavigationGroup::ADMIN], array_slice($cases, 3, 3));
    }

    // ---- what the browser gets ---------------------------------------------------------------------------------------------------------------

    public function test_the_editor_state_is_the_flat_list_with_what_each_target_is(): void
    {
        $page = app(PageWriter::class)->create(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>x</p>', 'status' => 'published']);
        $draft = app(PageWriter::class)->create(['title' => 'Draft', 'slug' => 'draft', 'body' => '<p>x</p>', 'status' => 'draft']);
        $this->saveMenu('main', [
            $this->menuItem('category', ['target_id' => 1, 'submenu' => 'dropdown', 'children' => [$this->menuItem('category', ['target_id' => 2, 'children' => [$this->menuItem('link', ['label' => 'Deep', 'url' => '/deep'])]])]]),
            $this->menuItem('category', ['target_id' => 4]),
            $this->menuItem('page', ['target_id' => $page->id]),
            $this->menuItem('page', ['target_id' => $draft->id]),
            $this->menuItem('product', ['target_id' => 2]),
        ]);
        DB::table('menu_items')->insert(['menu_id' => $this->menu()->id, 'sort' => 9, 'type' => 'page', 'target_id' => 999, 'submenu' => 'none', 'new_tab' => 0, 'visible' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $state = $this->page()->instance()->editorState();
        $items = $state['menu']['items'];

        $this->assertSame([0, 1, 2, 0, 0, 0, 0, 0], array_column($items, 'depth'));
        $this->assertSame(['category', 'category', 'link', 'category', 'page', 'page', 'product', 'page'], array_column($items, 'type'));
        $this->assertSame(['Clothing', 'Dresses'], [$items[0]['target_name'], $items[1]['target_name']]);
        $this->assertSame('/product-category/clothing/dresses', $items[1]['target_url']);
        $this->assertSame(['ok', 'ok', 'ok', 'hidden', 'ok', 'hidden', 'ok', 'deleted'], array_column($items, 'target_status'), 'Empty (no product), a draft page and a deleted page say why they are missing in the shop');
        $this->assertSame('/product/boot', $items[6]['target_url']);
        $this->assertSame(['main', 'footer'], array_column($state['menus'], 'key'));
        $this->assertSame(['Clothing', 'Dresses', 'Empty', 'Shoes'], array_column($state['categories'], 'name'), 'the tree in reading order: children right after their parent');
        $this->assertSame([0, 1, 0, 0], array_column($state['categories'], 'depth'));
        $this->assertSame(['Terms'], array_column($state['pages'], 'title'), 'published pages only');
        $this->assertSame(['header'], app(MenuLocations::class)->of($this->menu()->id), 'the main menu starts as the header menu');
    }

    public function test_the_html_blocks_and_locations_are_in_the_state(): void
    {
        HtmlBlockModel::create(['title' => 'Boys picture', 'body' => '<p>x</p>']);
        $state = $this->page()->instance()->editorState();

        $this->assertSame(['Boys picture'], array_column($state['blocks'], 'title'));
        $this->assertSame(['header', 'drawer', 'footer'], array_column($state['locations'], 'key'));
        $this->assertSame('Main menu', $state['holders']['header']['name']);
        $this->assertSame(['header'], $state['menu']['locations']);
        $this->assertSame(['red', 'green', 'orange', 'blue', 'dark', 'grey'], $state['presets']);
    }

    public function test_a_mega_item_comes_to_the_browser_with_its_columns_as_depth_one_entries(): void
    {
        $this->saveMenu('main', [$this->megaItem('Dresses', '/d', 4, [
            $this->columnItem([$this->menuItem('category', ['target_id' => 1])], 'Clothes'),
            $this->columnItem(),
        ])]);

        $state = $this->page()->instance()->editorState();
        $items = $state['menu']['items'];

        $this->assertSame(4, $state['limits']['columns'], 'at most four columns');
        $this->assertSame([0, 1, 2, 1, 1, 1], array_column($items, 'depth'));
        $this->assertSame(['link', 'column', 'category', 'column', 'column', 'column'], array_column($items, 'type'));
        $this->assertSame([4, null, null], [$items[0]['mega_columns'], $items[1]['mega_columns'], $items[2]['mega_columns']]);
        $this->assertSame(['Clothes', null, null, null], [$items[1]['label'], $items[3]['label'], $items[4]['label'], $items[5]['label']]);
    }

    public function test_the_whole_mega_menu_is_saved_in_one_call_with_its_columns_and_links(): void
    {
        $page = $this->page();
        $mega = $this->link('Dresses', 0, ['submenu' => 'mega', 'mega_columns' => 4]);
        $column = fn (?string $heading = null): array => $this->row('column', 1, ['label' => $heading]);
        $cats = fn (int ...$ids): array => array_map(fn (int $id): array => $this->row('category', 2, ['target_id' => $id]), $ids);

        $result = $page->instance()->saveMenu($this->menu()->id, 'Main', [$mega, $column(), ...$cats(1, 2), $column('Shoes'), ...$cats(3), $column(), $column()], ['header']);

        $this->assertTrue($result['ok'], json_encode($result));
        $tree = MenuTree::forMenu($this->menu()->id);
        $this->assertSame([4, [2, 1, 0, 0], [null, 'Shoes', null, null]], [$tree[0]['mega_columns'], array_map(fn (array $c): int => count($c['children']), $tree[0]['children']), array_column($tree[0]['children'], 'label')]);
        $this->assertSame([0, 1, 2, 2, 1, 2, 1, 1], array_column($result['menu']['items'], 'depth'), 'the browser gets back the stored list');
    }

    public function test_the_product_search_is_server_side_by_name_or_sku_and_gives_twenty_at_most(): void
    {
        for ($i = 10; $i < 40; $i++) {
            $this->makeProduct($i, ['slug' => "bulk-{$i}", 'categories' => [3], 'price' => '5.00', 'stock' => 1, 'name' => "Bulk item {$i}", 'sku' => "BULK-{$i}"]);
        }

        $page = $this->page();

        $this->assertSame([], $page->instance()->searchProducts('   '), 'nothing asked, nothing sent');
        $this->assertCount(20, $page->instance()->searchProducts('Bulk'), 'at most twenty');
        $this->assertSame(['Winter boot'], array_column($page->instance()->searchProducts('boot'), 'name'));
        $found = $page->instance()->searchProducts('BASE-17');
        $this->assertSame(['Bulk item 17'], array_column($found, 'name'), 'by SKU');
        $this->assertSame(['id', 'name', 'sku', 'url'], array_keys($found[0]), 'id, name, SKU and address only');
        $this->assertSame([], $page->instance()->searchProducts('%'), 'a wildcard is text');
    }

    // ---- the one save ------------------------------------------------------------------------------------------------------------------------

    public function test_the_flat_list_becomes_a_tree_by_depth_and_is_saved_in_order(): void
    {
        $page = $this->page();

        $result = $page->instance()->saveMenu($this->menu()->id, 'Renamed', [
            $this->link('First', 0),
            $this->row('category', 0, ['target_id' => 1, 'submenu' => 'dropdown']),
            $this->row('category', 1, ['target_id' => 2]),
            $this->link('Deep', 2),
            $this->link('Second child', 1),
            $this->link('Last', 0),
        ], ['header']);

        $this->assertTrue($result['ok'], json_encode($result));
        $tree = MenuTree::forMenu($this->menu()->id);
        $this->assertSame(['First', null, 'Last'], array_column($tree, 'label'));
        $this->assertSame(['category', 'link'], array_column($tree[1]['children'], 'type'));
        $this->assertSame(['Deep'], array_column($tree[1]['children'][0]['children'], 'label'));
        $this->assertSame('Renamed', $this->menu()->name);
        $this->assertSame('Renamed', $result['menu']['name']);
        $this->assertSame([0, 0, 1, 2, 1, 0], array_column($result['menu']['items'], 'depth'), 'the answer is the saved list: the browser shows what is stored');
        $this->assertStringContainsString('Clothing', (string) app(SiteSettingsRepository::class)->get(AppearanceSettings::MENUS_COMPILED_KEY), 'compiled by the save');
    }

    public function test_a_jump_of_more_than_one_level_is_refused_and_nothing_is_written(): void
    {
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Keep', 'url' => '/keep'])]);
        $page = $this->page();

        $result = $page->instance()->saveMenu($this->menu()->id, 'Other', [$this->link('A', 0), $this->link('B', 2)], ['header']);

        $this->assertFalse($result['ok']);
        $this->assertSame([1], array_keys($result['items']), 'keyed by the index of the faulty item');
        $this->assertStringContainsString('one level', $result['items'][1]['_item']);
        $this->assertSame(['Keep'], array_column(MenuTree::forMenu($this->menu()->id), 'label'));
        $this->assertNotSame('Other', $this->menu()->name);
    }

    public function test_depth_over_two_and_a_first_item_that_is_not_at_the_top_are_refused(): void
    {
        $page = $this->page();

        $deep = $page->instance()->saveMenu($this->menu()->id, 'Main', [$this->link('A', 0), $this->link('B', 1), $this->link('C', 2), $this->link('D', 3)], ['header']);
        $this->assertFalse($deep['ok']);
        $this->assertSame([3], array_keys($deep['items']));
        $this->assertStringContainsString('three levels', $deep['items'][3]['_item']);

        $first = $page->instance()->saveMenu($this->menu()->id, 'Main', [$this->link('A', 1)], ['header']);
        $this->assertFalse($first['ok']);
        $this->assertSame([0], array_keys($first['items']));

        $nonsense = $page->instance()->saveMenu($this->menu()->id, 'Main', [['depth' => 'deep'] + $this->link('A', 0)], ['header']);
        $this->assertFalse($nonsense['ok']);
    }

    public function test_the_flat_converter_refuses_depth_three_by_itself(): void
    {
        [, , $errors] = MenuFlat::toTree([$this->link('A', 0), $this->link('B', 1), $this->link('C', 2), $this->link('D', 3)]);

        $this->assertSame([3], array_keys($errors));
    }

    public function test_a_column_is_only_allowed_directly_under_a_mega_item_and_a_mega_item_holds_exactly_its_columns(): void
    {
        $page = $this->page();
        $mega = fn (int $n): array => $this->link('Boys', 0, ['submenu' => 'mega', 'mega_columns' => $n]);
        $column = fn (int $depth = 1, ?string $heading = null): array => $this->row('column', $depth, ['label' => $heading]);

        $ok = $page->instance()->saveMenu($this->menu()->id, 'Main', [$mega(2), $column(1, 'Clothes'), $this->link('Jackets', 2), $column(1)], ['header']);
        $this->assertTrue($ok['ok'], json_encode($ok));
        $saved = MenuTree::forMenu($this->menu()->id);
        $this->assertSame([2, ['Clothes', null]], [$saved[0]['mega_columns'], array_column($saved[0]['children'], 'label')]);
        $this->assertSame(['Jackets'], array_column($saved[0]['children'][0]['children'], 'label'));

        foreach ([
            'a column at the top' => [[$column(0)], 0, 'type'],
            'a column under a dropdown' => [[$this->link('D', 0, ['submenu' => 'dropdown']), $column(1)], 1, 'type'],
            'a column at the last level' => [[$mega(1), $column(1), $column(2)], 2, 'type'],
            'a link directly under a mega item' => [[$mega(1), $this->link('Direct', 1)], 1, 'type'],
            'fewer columns than chosen' => [[$mega(3), $column(1), $column(1)], 0, '_item'],
            'more columns than chosen' => [[$mega(1), $column(1), $column(1)], 0, '_item'],
            'a mega item without a number of columns' => [[$this->link('M', 0, ['submenu' => 'mega']), $column(1)], 0, 'mega_columns'],
            'five columns' => [[$mega(5), $column(1), $column(1), $column(1), $column(1), $column(1)], 0, 'mega_columns'],
        ] as $name => [$flat, $index, $field]) {
            $result = $page->instance()->saveMenu($this->menu()->id, 'Main', $flat, ['header']);

            $this->assertFalse($result['ok'], $name);
            $this->assertArrayHasKey($index, $result['items'], $name);
            $this->assertArrayHasKey($field, $result['items'][$index], $name.': the message is on the right field of that item');
        }

        $this->assertSame(['Boys'], array_column(MenuTree::forMenu($this->menu()->id), 'label'), 'the refused saves left the saved one');
    }

    public function test_an_invalid_item_writes_nothing_and_the_errors_are_keyed_by_item(): void
    {
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Keep', 'url' => '/keep'])]);
        $before = DB::table('menu_items')->orderBy('id')->get()->all();
        $page = $this->page();

        $result = $page->instance()->saveMenu($this->menu()->id, 'Renamed', [
            $this->link('Fine', 0),
            $this->link('Bad link', 0, ['url' => 'javascript:alert(1)']),
            $this->link('Fine too', 0),
            $this->link('Long badge', 0, ['badge_text' => str_repeat('b', 17)]),
        ], ['footer']);

        $this->assertFalse($result['ok']);
        $this->assertSame([1, 3], array_keys($result['items']));
        $this->assertArrayHasKey('url', $result['items'][1]);
        $this->assertArrayHasKey('badge_text', $result['items'][3]);
        $this->assertEquals($before, DB::table('menu_items')->orderBy('id')->get()->all(), 'not one row changed');
        $this->assertNotSame('Renamed', $this->menu()->name);
        $this->assertSame(['header'], app(MenuLocations::class)->of($this->menu()->id), 'and the locations stayed too');
    }

    public function test_a_custom_link_must_be_root_relative_or_https(): void
    {
        $page = $this->page();

        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test/x', 'http://example.com', 'mailto:a@b.c'] as $url) {
            $result = $page->instance()->saveMenu($this->menu()->id, 'Main', [$this->link('X', 0, ['url' => $url])], ['header']);

            $this->assertFalse($result['ok'], $url);
            $this->assertArrayHasKey('url', $result['items'][0], $url);
        }

        foreach (['/sale', 'https://example.com/a'] as $url) {
            $this->assertTrue($page->instance()->saveMenu($this->menu()->id, 'Main', [$this->link('X', 0, ['url' => $url])], ['header'])['ok'], $url);
        }
    }

    public function test_a_location_ticked_here_moves_from_the_menu_that_had_it(): void
    {
        $page = $this->page();
        $footer = $this->menu('footer');
        $this->assertSame(['footer'], app(MenuLocations::class)->of($footer->id));

        $result = $page->instance()->saveMenu($this->menu()->id, 'Main menu', [$this->link('A', 0)], ['header', 'footer']);

        $this->assertTrue($result['ok']);
        $this->assertEqualsCanonicalizing(['header', 'footer'], app(MenuLocations::class)->of($this->menu()->id));
        $this->assertSame([], app(MenuLocations::class)->of($footer->id), 'one menu per location');
        $this->assertStringContainsString('Footer', implode(' ', $result['moved']));
        $this->assertSame('main', app(MenuLocations::class)->keys()['footer']);

        // unticked: it lets go of the one it had
        $page->instance()->saveMenu($this->menu()->id, 'Main menu', [$this->link('A', 0)], ['header']);
        $this->assertSame(['header'], app(MenuLocations::class)->of($this->menu()->id));
        $this->assertNull(app(MenuLocations::class)->keys()['footer']);

        $unknown = $page->instance()->saveMenu($this->menu()->id, 'Main menu', [$this->link('A', 0)], ['sidebar']);
        $this->assertFalse($unknown['ok']);
        $this->assertArrayHasKey('locations', $unknown['menu']);
    }

    public function test_the_location_decides_what_the_shop_shows(): void
    {
        $page = $this->page();
        $page->instance()->saveMenu($this->menu('footer')->id, 'Footer', [$this->link('Footerlink', 0)], ['header', 'footer']);
        $this->app->forgetScopedInstances();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<a class="sf-bar-link" href="/footerlink">Footerlink</a>', $html, 'the header now follows the footer menu');
        $this->assertStringContainsString('<li><a href="/footerlink">Footerlink</a></li>', $html, 'and so does the footer');
    }

    // ---- create and delete -------------------------------------------------------------------------------------------------------------------

    public function test_a_new_menu_gets_a_key_from_its_name_and_is_empty(): void
    {
        $page = $this->page();

        $first = $page->instance()->createMenu('Момчета меню');
        $second = $page->instance()->createMenu('Момчета меню');

        $this->assertTrue($first['ok']);
        $this->assertSame('momcheta-menyu', $first['menu']['key']);
        $this->assertSame('momcheta-menyu-2', $second['menu']['key'], 'a taken key gets a number');
        $this->assertSame([], $first['menu']['items']);
        $this->assertCount(4, $second['menus']);
        $this->assertFalse($page->instance()->createMenu('   ')['ok'], 'a name is needed');
    }

    public function test_the_seeded_menus_and_a_menu_that_holds_a_location_cannot_be_deleted(): void
    {
        $page = $this->page();

        foreach (['main', 'footer'] as $key) {
            $result = $page->instance()->deleteMenu($this->menu($key)->id);
            $this->assertFalse($result['ok'], $key);
        }

        $extra = $page->instance()->createMenu('Extra')['menu'];
        app(MenuLocations::class)->assign($extra['id'], ['drawer']);
        $refused = $page->instance()->deleteMenu($extra['id']);
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('Phone', $refused['message']);

        app(MenuLocations::class)->release($extra['id']);
        $this->assertTrue($page->instance()->deleteMenu($extra['id'])['ok']);
        $this->assertNull(MenuModel::query()->where('key', 'extra')->first());
    }

    // ---- the page itself -------------------------------------------------------------------------------------------------------------------------

    public function test_the_admin_script_is_on_this_page_only_never_on_another_admin_page_nor_the_shop(): void
    {
        $this->actingAsStaff('Administrator');

        $this->assertStringContainsString('admin-menu-editor', $this->get(Menus::getUrl())->assertOk()->getContent());

        foreach ([DesignScreen::getUrl(), AppearancePage::getUrl(), PageResource::getUrl('index'), HtmlBlockResource::getUrl('index'), '/admin'] as $url) {
            $this->assertStringNotContainsString('admin-menu-editor', $this->get($url)->assertOk()->getContent(), $url);
        }

        $this->assertStringNotContainsString('admin-menu-editor', $this->get('/')->assertOk()->getContent());
        $this->assertStringNotContainsString('sortablejs', (string) file_get_contents(resource_path('js/storefront.js')));
        $this->assertStringNotContainsString('Sortable', (string) file_get_contents(resource_path('js/storefront.js')));
    }

    public function test_the_page_has_the_unsaved_changes_guard_and_the_aria_live_announcement(): void
    {
        $this->actingAsStaff('Administrator');
        $html = $this->get(Menus::getUrl())->assertOk()->getContent();
        $script = (string) file_get_contents(resource_path('js/admin-menu-editor.js'));

        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('wire:ignore', $html);
        $this->assertStringContainsString('x-data="menuEditor(', $html);
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $script);
        $this->assertStringContainsString("document.addEventListener('livewire:navigate'", $script);
        $this->assertStringContainsString('get dirty()', $script);
        $this->assertStringContainsString('me-dot', $html, 'the dot next to «Запази менюто»');
        $this->assertStringContainsString('Save menu', $html);
        $this->assertStringContainsString('Delete menu', $html);
        $this->assertStringContainsString('Create a new menu', $html);
    }

    public function test_the_page_is_in_bulgarian_too(): void
    {
        $this->actingAsStaff('Administrator');
        app()->setLocale('bg');
        $html = $this->get(Menus::getUrl())->assertOk()->getContent();

        foreach (['Запази менюто', 'Изтрий менюто', 'Създай ново меню', 'Добавяне на елементи', 'Структура на менюто', 'Собствена връзка'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertSame(__('menus.editor.moved', ['position' => 3, 'total' => 8, 'level' => 2]), 'Преместено на позиция 3 от 8, ниво 2');
    }

    public function test_the_help_anchors_exist_in_both_languages_and_the_pages_link_to_them(): void
    {
        foreach (['bg', 'en'] as $locale) {
            $help = (string) file_get_contents(resource_path("help/{$locale}/appearance.md"));

            $this->assertStringContainsString('{#action-appearance-menus}', $help);
            $this->assertStringContainsString('{#action-appearance-html-blocks}', $help);
        }

        $this->actingAsStaff('Administrator');
        $this->assertMatchesRegularExpression('#<a href="[^"]*/admin/help/appearance\#action-appearance-menus"#', $this->get(Menus::getUrl())->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#<a href="[^"]*/admin/help/appearance\#action-appearance-html-blocks"#', $this->get(HtmlBlockResource::getUrl('index'))->assertOk()->getContent());
    }

    public function test_the_old_editor_is_gone(): void
    {
        $this->assertFalse(class_exists(\App\Filament\Resources\MenuResource::class));
        $this->assertFalse(class_exists(\App\Content\Menus\MenuFormState::class));
        $this->actingAsStaff('Administrator');
        $this->assertSame(url('/admin/menus'), Menus::getUrl());
        $this->get('/admin/menus/1/edit')->assertNotFound();
    }

    public function test_the_page_data_costs_a_fixed_number_of_queries(): void
    {
        $this->saveMenu('main', array_map(fn (int $i): array => $this->menuItem('category', ['target_id' => 1 + ($i % 3)]), range(1, 12)));
        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            app(MenuEditorData::class)->menu($this->menu());

            return $n;
        };

        $this->assertLessThanOrEqual(12, $count(), 'the targets are loaded in a few queries, not one per item');
    }
}
