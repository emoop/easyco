<?php

namespace Tests\Feature\Admin;

use App\Content\Menus\MenuFormState;
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

    public function test_another_menu_can_be_created_and_deleted_and_the_name_of_the_place_is_in_the_message_while_the_header_uses_it(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(ListMenus::class)->callAction('create_menu', ['name' => 'Sale menu', 'key' => 'sale'])->assertHasNoActionErrors();
        $menu = $this->menu('sale');
        $this->assertSame('Sale menu', $menu->name);

        // The header draft uses it: refused, and the message names the place.
        $layout = \App\Storefront\Header\HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'] = [['type' => 'menu', 'settings' => ['source' => 'sale', 'style' => 'links']]];
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode($layout));

        try {
            app(\App\Content\Menus\MenuWriter::class)->delete($menu);
            $this->fail('the menu is in use');
        } catch (\App\Content\Menus\MenuException $exception) {
            $this->assertStringContainsString('the header draft', $exception->getMessage());
            $this->assertStringContainsString('Sale menu', $exception->getMessage());
        }

        $this->assertTrue($menu->fresh() !== null);

        $this->settings()->forget(AppearanceSettings::HEADER_DRAFT_KEY);
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
        $tree = [
            ['type' => 'category', 'label' => null, 'url' => null, 'target_id' => 1, 'submenu' => 'dropdown', 'with_children' => false, 'children_limit' => 12, 'media_asset_id' => null, 'image_alt' => null, 'caption' => null, 'new_tab' => false, 'visible' => true, 'children' => [
                ['type' => 'link', 'label' => 'A', 'url' => '/a', 'target_id' => null, 'submenu' => 'none', 'with_children' => false, 'children_limit' => 12, 'media_asset_id' => null, 'image_alt' => null, 'caption' => null, 'new_tab' => true, 'visible' => true, 'children' => [
                    ['type' => 'page', 'label' => null, 'url' => null, 'target_id' => 7, 'submenu' => 'none', 'with_children' => false, 'children_limit' => 12, 'media_asset_id' => null, 'image_alt' => null, 'caption' => null, 'new_tab' => false, 'visible' => true, 'children' => []],
                ]],
            ]],
            ['type' => 'link', 'label' => 'Mega', 'url' => '/m', 'target_id' => null, 'submenu' => 'mega', 'with_children' => false, 'children_limit' => 12, 'media_asset_id' => null, 'image_alt' => null, 'caption' => null, 'new_tab' => false, 'visible' => true, 'children' => [
                ['type' => 'column', 'label' => 'Col', 'visible' => true, 'children' => [
                    ['type' => 'image', 'label' => null, 'url' => '/p', 'target_id' => null, 'submenu' => 'none', 'with_children' => false, 'children_limit' => 12, 'media_asset_id' => 5, 'image_alt' => 'Alt', 'caption' => 'Cap', 'new_tab' => false, 'visible' => true, 'children' => []],
                ]],
            ]],
        ];

        $state = MenuFormState::toForm($tree);
        [$back, $map, $types] = MenuFormState::fromForm(array_combine(array_map(fn (int $i): string => "k{$i}", array_keys($state)), $state));

        $this->assertSame(1, $back[0]['target_id']);
        $this->assertSame('/a', $back[0]['children'][0]['url']);
        $this->assertSame(7, $back[0]['children'][0]['children'][0]['target_id']);
        $this->assertSame(5, $back[1]['children'][0]['children'][0]['media_asset_id']);
        $this->assertSame('items.k0.dropdown_items.0.sub_items.0.page_id', MenuFormState::fieldFor('items.0.children.0.children.0.target_id', $map, $types));
        $this->assertSame('items.k1.columns.0.column_items.0.image_file', MenuFormState::fieldFor('items.1.children.0.children.0.media_asset_id', $map, $types));
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
        $this->assertSame(['dropdown', 'dropdown'][1], $items[1]['submenu']);
        $this->assertTrue((bool) $items[1]['with_children']);

        // A second press adds nothing.
        $editor->callAction('add_main_categories');
        $this->assertCount(2, $editor->get('data.items'));
    }

    public function test_the_subcategories_helper_adds_them_as_explicit_items_and_turns_the_automatic_list_off(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 1, 'submenu' => 'dropdown', 'with_children' => true])]);
        $this->actingAsStaff('Administrator');

        $editor = Livewire::test(EditMenu::class, ['record' => $this->menu()->id]);
        $key = array_key_first($editor->get('data.items'));
        $editor->callFormComponentAction('items', 'add_subcategories', arguments: ['item' => $key]);

        $item = $editor->get("data.items.{$key}");
        $children = array_values($item['dropdown_items']);

        $this->assertSame([2], array_map(fn (array $child): int => (int) $child['category_id'], $children));
        $this->assertFalse((bool) $item['with_children'], 'explicit items replace the automatic list');

        $editor->call('save')->assertHasNoFormErrors();
        $this->assertSame(['category'], array_column(MenuTree::forMenu($this->menu()->id)[0]['children'], 'type'));
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
        $image = $this->makeMenuImage(930);
        $this->actingAsStaff('Administrator');
        $item = fn (string $type, array $more = []): array => $more + ['type' => $type, 'label' => null, 'url' => null, 'category_id' => null, 'page_id' => null, 'submenu' => 'none', 'with_children' => false,
            'children_limit' => 12, 'media_asset_id' => null, 'image_alt' => null, 'caption' => null, 'new_tab' => false, 'visible' => true];
        $items = [
            'i1' => $item('link', ['label' => 'Начало', 'url' => '/', 'new_tab' => true]),
            'i2' => $item('category', ['category_id' => 1, 'label' => 'Дамски', 'submenu' => 'dropdown', 'with_children' => true, 'children_limit' => 5, 'dropdown_items' => [
                'd1' => $item('link', ['label' => 'Промо', 'url' => '/promo']) + ['sub_items' => []],
                'd2' => $item('page', ['page_id' => $page->id, 'label' => 'Правила']) + ['sub_items' => ['s1' => $item('link', ['label' => 'Дълбоко', 'url' => '/deep'])]],
            ]]),
            'i3' => $item('link', ['label' => 'Мега', 'url' => '/mega', 'submenu' => 'mega', 'columns' => [
                'c1' => ['label' => 'Колона едно', 'visible' => true, 'column_items' => ['x1' => $item('link', ['label' => 'Рокли', 'url' => '/dresses']), 'x2' => $item('category', ['category_id' => 3]), 'x3' => $item('page', ['page_id' => $page->id])]],
                'c2' => ['label' => 'Колона две', 'visible' => true, 'column_items' => ['y1' => $item('image', ['media_asset_id' => $image, 'image_alt' => 'Снимка', 'caption' => 'Надпис', 'url' => '/promo-image', 'new_tab' => true])]],
            ]]),
            'i4' => $item('page', ['page_id' => $page->id, 'visible' => false]),
        ];

        Livewire::test(EditMenu::class, ['record' => $this->menu()->id])->set('data.name', 'Одит меню')->set('data.items', $items)->call('save')->assertHasNoFormErrors();

        $this->assertSame('Одит меню', $this->menu()->name);
        $tree = MenuTree::forMenu($this->menu()->id);
        $this->assertSame(['link', 'category', 'link', 'page'], array_column($tree, 'type'), 'the order of the top level');
        $this->assertSame([true, false, false, false], array_map(fn (array $i): bool => $i['new_tab'], $tree), 'new_tab on the first');
        $this->assertSame([5, true], [$tree[1]['children_limit'], $tree[1]['with_children']]);
        $this->assertSame(['link', 'page'], array_column($tree[1]['children'], 'type'));
        $this->assertSame('Дълбоко', $tree[1]['children'][1]['children'][0]['label'], 'the second level');
        $this->assertSame(['column', 'column'], array_column($tree[2]['children'], 'type'));
        $this->assertSame(['link', 'category', 'page'], array_column($tree[2]['children'][0]['children'], 'type'), 'the column, in order');
        $this->assertSame([$image, 'Снимка', 'Надпис', '/promo-image'], [$tree[2]['children'][1]['children'][0]['media_asset_id'], $tree[2]['children'][1]['children'][0]['image_alt'], $tree[2]['children'][1]['children'][0]['caption'], $tree[2]['children'][1]['children'][0]['url']]);
        $this->assertFalse($tree[3]['visible']);

        // reopened: the form shows the same tree, in the same order
        Livewire::test(EditMenu::class, ['record' => $this->menu()->id])->assertFormSet(function (array $state): void {
            $top = array_values($state['items']);
            $this->assertSame(['Одит меню'], [$state['name']]);
            $this->assertSame(['link', 'category', 'link', 'page'], array_column($top, 'type'));
            $this->assertSame(['Начало', 'Дамски', 'Мега'], [$top[0]['label'], $top[1]['label'], $top[2]['label']]);
            $this->assertSame('dropdown', $top[1]['submenu']);
            $this->assertSame(['Промо', 'Правила'], array_column(array_values($top[1]['dropdown_items']), 'label'));
            $this->assertSame(['Колона едно', 'Колона две'], array_column(array_values($top[2]['columns']), 'label'));
        });

        // and the shop follows at once (the compiled menus were replaced by the save)
        $this->assertStringContainsString('Дамски', (string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY));
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
