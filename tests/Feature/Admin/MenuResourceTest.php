<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuFormState;
use App\Content\Menus\MenuLocations;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuTree;
use App\Content\PageWriter;
use App\Filament\NavigationGroup;
use App\Filament\Resources\MenuResource;
use App\Filament\Resources\MenuResource\Pages\EditMenu;
use App\Filament\Resources\MenuResource\Pages\ListMenus;
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
 * Stage D3: the admin «Менюта» - who may open it (`content_manage`), the protected and in-use menus cannot be deleted, the tree through the writer (errors on the
 * form's own fields), the two helpers, the warning for a crowded main menu, the activity log without labels or links, and the help anchor.
 */
class MenuResourceTest extends TestCase
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
        MenuResource::forgetMemo();
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [1, 2], 'price' => '10.00', 'stock' => 3]);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function menu(string $key = 'main'): MenuModel
    {
        return MenuModel::query()->where('key', $key)->firstOrFail();
    }

    // ---- access ----------------------------------------------------------------------------------------------------------------------

    public function test_the_content_roles_reach_the_tool_and_a_role_without_the_permission_does_not(): void
    {
        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);

            $this->get(MenuResource::getUrl('index'))->assertOk();
            $this->get(MenuResource::getUrl('edit', ['record' => $this->menu()]))->assertOk();
        }

        foreach ([fn () => $this->actingAsStaff('Product Entry'), fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW])] as $actAs) {
            $actAs();

            $this->get(MenuResource::getUrl('index'))->assertForbidden();
            $this->get(MenuResource::getUrl('edit', ['record' => $this->menu()]))->assertForbidden();
            $this->assertFalse(MenuResource::canAccess());
            Livewire::test(ListMenus::class)->assertForbidden();
        }
    }

    public function test_it_sits_in_the_content_group_after_the_pages(): void
    {
        $this->assertSame(NavigationGroup::CONTENT, MenuResource::getNavigationGroup());
        $this->assertGreaterThan(\App\Filament\Resources\PageResource::getNavigationSort(), MenuResource::getNavigationSort());
        $this->assertSame('Menus', MenuResource::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('Менюта', MenuResource::getNavigationLabel());
    }

    // ---- the list and deleting ---------------------------------------------------------------------------------------------------------

    public function test_the_two_seeded_menus_are_listed_and_cannot_be_deleted(): void
    {
        $this->actingAsStaff('Administrator');

        $list = Livewire::test(ListMenus::class)->assertCanSeeTableRecords(MenuModel::query()->get());

        foreach (['main', 'footer'] as $key) {
            $list->assertTableActionHidden('delete_menu', $this->menu($key));
        }

        $this->expectException(\App\Content\Menus\MenuException::class);
        app(\App\Content\Menus\MenuWriter::class)->delete($this->menu('main'));
    }

    public function test_another_menu_can_be_created_and_deleted_and_the_name_of_the_place_is_in_the_message_while_it_holds_a_location(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(ListMenus::class)->callAction('create_menu', ['name' => 'Sale menu', 'key' => 'sale'])->assertHasNoActionErrors();
        $menu = $this->menu('sale');
        $this->assertSame('Sale menu', $menu->name);

        // It holds the phone location: refused, and the message names the place.
        app(MenuLocations::class)->assign($menu->id, ['drawer']);

        try {
            app(\App\Content\Menus\MenuWriter::class)->delete($menu);
            $this->fail('the menu is in use');
        } catch (\App\Content\Menus\MenuException $exception) {
            $this->assertStringContainsString('Phone', $exception->getMessage());
            $this->assertStringContainsString('Sale menu', $exception->getMessage());
        }

        $this->assertTrue($menu->fresh() !== null);

        app(MenuLocations::class)->release($menu->id);
        Livewire::test(ListMenus::class)->callTableAction('delete_menu', $menu);
        $this->assertNull(MenuModel::query()->where('key', 'sale')->first());
    }

    public function test_a_bad_key_is_refused_in_the_create_action(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(ListMenus::class)->callAction('create_menu', ['name' => 'Bad', 'key' => 'Not Valid!'])->assertHasActionErrors(['key']);
        Livewire::test(ListMenus::class)->callAction('create_menu', ['name' => 'Dup', 'key' => 'main']);

        $this->assertSame(2, MenuModel::query()->count(), 'nothing was created');
    }

    // ---- the editor -------------------------------------------------------------------------------------------------------------------

    public function test_the_editor_opens_with_the_stored_tree_and_saves_a_change_through_the_writer(): void
    {
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Home', 'url' => '/']), $this->menuItem('category', ['target_id' => 1, 'submenu' => 'dropdown', 'children' => [$this->menuItem('link', ['label' => 'Sale', 'url' => '/sale'])]])]);
        $this->actingAsStaff('Administrator');

        $editor = Livewire::test(EditMenu::class, ['record' => $this->menu()->id]);
        $state = $editor->get('data.items');

        $this->assertCount(2, $state);
        $first = array_key_first($state);
        $editor->set("data.items.{$first}.label", 'Start')->set('data.name', 'The main menu')->call('save')->assertHasNoFormErrors();

        $this->assertSame('The main menu', $this->menu()->name);
        $this->assertSame(['Start', null], array_column(MenuTree::forMenu($this->menu()->id), 'label'));
        $this->assertSame('Sale', MenuTree::forMenu($this->menu()->id)[1]['children'][0]['label'], 'the dropdown item survived the round trip');
        $this->assertStringContainsString('Start', (string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY), 'saved means compiled');
    }

    public function test_a_validation_refusal_comes_back_on_the_field_it_belongs_to_and_nothing_is_saved(): void
    {
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Home', 'url' => '/'])]);
        $this->actingAsStaff('Administrator');

        $editor = Livewire::test(EditMenu::class, ['record' => $this->menu()->id]);
        $key = array_key_first($editor->get('data.items'));
        $editor->set("data.items.{$key}.url", 'javascript:alert(1)')->call('save');
        $editor->assertHasErrors(["data.items.{$key}.url"]);

        $this->assertSame('/', MenuTree::forMenu($this->menu()->id)[0]['url']);
    }

    public function test_the_form_state_round_trips_dropdowns_columns_and_the_path_of_an_error_finds_its_field(): void
    {
        $item = fn (string $type, array $more = [], array $children = []): array => $more + ['type' => $type, 'label' => null, 'url' => null, 'target_id' => null, 'submenu' => 'none', 'html_block_id' => null,
            'badge_text' => null, 'badge_color' => null, 'new_tab' => false, 'visible' => true, 'children' => $children];
        $tree = [
            $item('category', ['target_id' => 1, 'submenu' => 'dropdown'], [
                $item('link', ['label' => 'A', 'url' => '/a', 'new_tab' => true, 'badge_text' => 'New', 'badge_color' => 'green'], [$item('page', ['target_id' => 7])]),
            ]),
            $item('link', ['label' => 'Mega', 'url' => '/m', 'submenu' => 'mega', 'html_block_id' => 4], [
                $item('heading', ['label' => 'Col'], [$item('product', ['target_id' => 5]), $item('link', ['label' => 'L', 'url' => '/l'])]),
                $item('category', ['target_id' => 2, 'label' => 'Linked heading'], [$item('link', ['label' => 'M', 'url' => '/mm'])]),
            ]),
        ];

        $state = MenuFormState::toForm($tree);
        [$back, $map, $types] = MenuFormState::fromForm(array_combine(array_map(fn (int $i): string => "k{$i}", array_keys($state)), $state));

        $this->assertEquals($tree, $back, 'nothing is lost on the way through the form');
        $this->assertSame(['heading', 'category'], array_column($state[1]['columns'], 'type'));
        $this->assertSame('items.k0.dropdown_items.0.sub_items.0.page_id', MenuFormState::fieldFor('items.0.children.0.children.0.target_id', $map, $types));
        $this->assertSame('items.k1.columns.0.column_items.0.product_id', MenuFormState::fieldFor('items.1.children.0.children.0.target_id', $map, $types));
        $this->assertSame('items.k1.columns.1.category_id', MenuFormState::fieldFor('items.1.children.1.target_id', $map, $types), 'a category used as the column heading');
        $this->assertSame('items.k1.columns.0.label', MenuFormState::fieldFor('items.1.children.0.label', $map, $types));
        $this->assertSame('items.k1.html_block_id', MenuFormState::fieldFor('items.1.html_block_id', $map, $types));
        $this->assertSame('items.k0.dropdown_items.0.badge_color', MenuFormState::fieldFor('items.0.children.0.badge_color', $map, $types));
        $this->assertSame('items.k0.dropdown_items.0.url', MenuFormState::fieldFor('items.0.children.0.url', $map, $types));
        $this->assertSame('items', MenuFormState::fieldFor('items', $map, $types));
    }

    public function test_the_helper_adds_only_the_top_level_categories_that_are_missing(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3])]);
        $this->actingAsStaff('Administrator');

        $editor = Livewire::test(EditMenu::class, ['record' => $this->menu()->id])->callAction('add_main_categories');
        $items = array_values($editor->get('data.items'));

        $this->assertSame([3, 1], array_map(fn (array $item): int => (int) $item['category_id'], $items), 'Shoes was there; only Clothing was added');
        $this->assertSame('dropdown', $items[1]['submenu']);
        $this->assertSame([2], array_map(fn (array $child): int => (int) $child['category_id'], array_values($items[1]['dropdown_items'])), 'its sub-categories are explicit items');

        // A second press adds nothing.
        $editor->callAction('add_main_categories');
        $this->assertCount(2, $editor->get('data.items'));
    }

    public function test_the_subcategories_helper_adds_them_as_explicit_items(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 1, 'submenu' => 'dropdown', 'children' => [$this->menuItem('link', ['label' => 'Own', 'url' => '/own'])]])]);
        $this->actingAsStaff('Administrator');

        $editor = Livewire::test(EditMenu::class, ['record' => $this->menu()->id]);
        $key = array_key_first($editor->get('data.items'));
        $editor->callFormComponentAction('items', 'add_subcategories', arguments: ['item' => $key]);

        $item = $editor->get("data.items.{$key}");
        $children = array_values($item['dropdown_items']);

        $this->assertSame(['link', 'category'], array_column($children, 'type'), 'the own link stays, the sub-category is added after it');
        $this->assertSame(2, (int) $children[1]['category_id']);

        $editor->call('save')->assertHasNoFormErrors();
        $this->assertSame(['link', 'category'], array_column(MenuTree::forMenu($this->menu()->id)[0]['children'], 'type'));
    }

    public function test_a_crowded_main_menu_gets_a_warning_and_is_still_saved(): void
    {
        $this->assertNull(MenuResource::warning([['label' => 'A'], ['label' => 'B']]));
        $this->assertNotNull(MenuResource::warning(array_fill(0, 8, ['label' => 'Short'])), 'more than 7 items');
        $this->assertNotNull(MenuResource::warning([['label' => str_repeat('x', 21)]]), 'a label over 20 characters');
        $this->assertNull(MenuResource::warning(array_fill(0, 7, ['label' => str_repeat('x', 20)])), 'seven items of twenty characters are fine');
        $this->assertStringContainsString('"More"', MenuResource::warning(array_fill(0, 8, [])));
        app()->setLocale('bg');
        $this->assertStringContainsString('„Още“', (string) MenuResource::warning(array_fill(0, 8, [])));

        $this->actingAsStaff('Administrator');
        app()->setLocale('en');
        $items = [];

        for ($i = 1; $i <= 9; $i++) {
            $items[] = $this->menuItem('link', ['label' => "Item {$i}", 'url' => "/i{$i}"]);
        }

        $this->saveMenu('main', array_slice($items, 0, 9));
        Livewire::test(EditMenu::class, ['record' => $this->menu()->id])->assertSee('Item 1')->call('save')->assertHasNoFormErrors();
    }

    public function test_every_save_is_journalled_who_and_which_menu_but_never_a_label_or_a_link(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $staff = $this->actingAsStaff('Administrator');
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Secret label', 'url' => 'https://secret.example.com/x'])]);

        Livewire::test(EditMenu::class, ['record' => $this->menu()->id])->call('save')->assertHasNoFormErrors();

        $rows = DB::table('activity_log')->where('entity_type', 'storefront_menu')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('main', $rows[0]->entity_id);
        $this->assertSame($staff->name, $rows[0]->staff_name);
        $dump = (string) json_encode($rows->all());
        $this->assertStringNotContainsString('Secret', $dump);
        $this->assertStringNotContainsString('secret.example', $dump);
    }

    // ---- the page form and the help --------------------------------------------------------------------------------------------------------

    public function test_the_page_form_no_longer_has_a_footer_flag_and_points_to_the_menus(): void
    {
        $page = app(PageWriter::class)->create(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>x</p>', 'status' => 'published']);
        $this->actingAsStaff('Administrator');

        $html = $this->get(\App\Filament\Resources\PageResource::getUrl('edit', ['record' => $page]))->assertOk()->getContent();

        $this->assertStringNotContainsString('wire:model="data.in_footer"', $html);
        $this->assertStringNotContainsString('data.footer_order', $html);
        $this->assertStringContainsString('The footer is arranged in Menus', $html);
        $this->assertStringContainsString(MenuResource::getUrl('index'), $html);
    }

    public function test_the_help_anchor_exists_in_both_languages_and_the_tool_links_to_it(): void
    {
        foreach (['bg', 'en'] as $locale) {
            $this->assertStringContainsString('{#action-appearance-menus}', (string) file_get_contents(resource_path("help/{$locale}/appearance.md")));
        }

        $this->actingAsStaff('Administrator');

        foreach ([MenuResource::getUrl('index'), MenuResource::getUrl('edit', ['record' => $this->menu()])] as $url) {
            $this->assertMatchesRegularExpression('#<a href="[^"]*/admin/help/appearance\#action-appearance-menus"#', $this->get($url)->assertOk()->getContent(), $url);
        }
    }

    // UX-0: a value the person typed is the value they get back: every item type, a dropdown with a second level, a mega panel with two columns and a picture, in order.
    public function test_every_field_survives_a_save_and_a_reload(): void
    {
        $page = app(PageWriter::class)->create(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>x</p>', 'status' => 'published']);
        $block = HtmlBlockModel::create(['title' => 'Boys picture', 'body' => '<p>Hello</p>']);
        $this->actingAsStaff('Administrator');
        $item = fn (string $type, array $more = []): array => $more + ['type' => $type, 'label' => null, 'url' => null, 'category_id' => null, 'page_id' => null, 'product_id' => null, 'submenu' => 'none',
            'html_block_id' => null, 'badge_text' => null, 'badge_color' => null, 'new_tab' => false, 'visible' => true];
        $items = [
            'i1' => $item('link', ['label' => 'Начало', 'url' => '/', 'new_tab' => true, 'badge_text' => 'Ново', 'badge_color' => 'green']),
            'i2' => $item('category', ['category_id' => 1, 'label' => 'Дамски', 'submenu' => 'dropdown', 'dropdown_items' => [
                'd1' => $item('link', ['label' => 'Промо', 'url' => '/promo']) + ['sub_items' => []],
                'd2' => $item('page', ['page_id' => $page->id, 'label' => 'Правила']) + ['sub_items' => ['s1' => $item('link', ['label' => 'Дълбоко', 'url' => '/deep'])]],
            ]]),
            'i3' => $item('link', ['label' => 'Мега', 'url' => '/mega', 'submenu' => 'mega', 'html_block_id' => $block->id, 'columns' => [
                'c1' => $item('heading', ['label' => 'Колона едно']) + ['column_items' => ['x1' => $item('link', ['label' => 'Рокли', 'url' => '/dresses', 'badge_text' => 'Sale', 'badge_color' => '#1a2b3c']), 'x2' => $item('category', ['category_id' => 3]), 'x3' => $item('page', ['page_id' => $page->id]), 'x4' => $item('product', ['product_id' => 1])]],
                'c2' => $item('category', ['category_id' => 3, 'label' => 'Колона две']) + ['column_items' => ['y1' => $item('link', ['label' => 'Обувки', 'url' => '/shoes'])]],
            ]]),
            'i4' => $item('page', ['page_id' => $page->id, 'visible' => false]),
        ];

        Livewire::test(EditMenu::class, ['record' => $this->menu()->id])->set('data.name', 'Одит меню')->set('data.items', $items)->set('data.locations', ['header', 'footer'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('Одит меню', $this->menu()->name);
        $tree = MenuTree::forMenu($this->menu()->id);
        $this->assertSame(['link', 'category', 'link', 'page'], array_column($tree, 'type'), 'the order of the top level');
        $this->assertSame([true, false, false, false], array_map(fn (array $i): bool => $i['new_tab'], $tree), 'new_tab on the first');
        $this->assertSame(['Ново', 'green'], [$tree[0]['badge_text'], $tree[0]['badge_color']]);
        $this->assertSame(['link', 'page'], array_column($tree[1]['children'], 'type'));
        $this->assertSame('Дълбоко', $tree[1]['children'][1]['children'][0]['label'], 'the second level');
        $this->assertSame($block->id, $tree[2]['html_block_id']);
        $this->assertSame(['heading', 'category'], array_column($tree[2]['children'], 'type'), 'the columns, in order: a heading and a category used as the heading');
        $this->assertSame(['link', 'category', 'page', 'product'], array_column($tree[2]['children'][0]['children'], 'type'), 'the column, in order');
        $this->assertSame(['Sale', '#1a2b3c'], [$tree[2]['children'][0]['children'][0]['badge_text'], $tree[2]['children'][0]['children'][0]['badge_color']]);
        $this->assertFalse($tree[3]['visible']);
        $this->assertEqualsCanonicalizing(['header', 'footer'], app(MenuLocations::class)->of($this->menu()->id));

        // reopened: the form shows the same tree, in the same order
        Livewire::test(EditMenu::class, ['record' => $this->menu()->id])->assertFormSet(function (array $state) use ($block): void {
            $top = array_values($state['items']);
            $this->assertSame(['Одит меню'], [$state['name']]);
            $this->assertSame(['link', 'category', 'link', 'page'], array_column($top, 'type'));
            $this->assertSame(['Начало', 'Дамски', 'Мега'], [$top[0]['label'], $top[1]['label'], $top[2]['label']]);
            $this->assertSame('dropdown', $top[1]['submenu']);
            $this->assertSame(['Промо', 'Правила'], array_column(array_values($top[1]['dropdown_items']), 'label'));
            $this->assertSame(['Колона едно', 'Колона две'], array_column(array_values($top[2]['columns']), 'label'));
            $this->assertSame(['heading', 'category'], array_column(array_values($top[2]['columns']), 'type'));
            $this->assertSame($block->id, (int) $top[2]['html_block_id']);
            $this->assertEqualsCanonicalizing(['header', 'footer'], $state['locations']);
        });

        // and the shop follows at once (the compiled menus were replaced by the save)
        $this->assertStringContainsString('Дамски', (string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY));
    }

    public function test_a_location_ticked_here_moves_from_the_menu_that_had_it(): void
    {
        $this->actingAsStaff('Administrator');
        $footer = $this->menu('footer');
        $this->assertContains('footer', app(MenuLocations::class)->of($footer->id));

        $this->assertStringContainsString('Footer', (string) MenuResource::movesNote($this->menu('main')), 'the note names what would move');

        Livewire::test(EditMenu::class, ['record' => $this->menu()->id])->set('data.locations', ['header', 'footer'])->call('save')->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['header', 'footer'], app(MenuLocations::class)->of($this->menu()->id));
        $this->assertSame([], app(MenuLocations::class)->of($footer->id), 'one menu per location: the footer menu let go of it');
        $this->assertSame($this->menu()->key, app(MenuLocations::class)->keys()['footer']);
    }

    // UX-0: reordering was switched off by `->reorderable(false)`: the editor showed no up / down buttons at all (the audit's "reorder" step found it).
    public function test_the_items_can_be_moved_up_and_down(): void
    {
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'A', 'url' => '/a']), $this->menuItem('link', ['label' => 'B', 'url' => '/b'])]);
        $this->actingAsStaff('Administrator');

        $html = $this->get(MenuResource::getUrl('edit', ['record' => $this->menu()]))->assertOk()->getContent();
        $this->assertStringContainsString('Move up', $html);
        $this->assertStringContainsString('Move down', $html);
    }
}
